<?php

declare(strict_types=1);

namespace Naf\Storage\Adapters;

use ErrorException;
use Naf\Storage\Exceptions\FileNotFoundException;
use Naf\Storage\Exceptions\StorageException;
use Naf\Storage\Exceptions\UnableToWriteException;
use Naf\Storage\StorageAdapterInterface;
use Naf\Storage\Support\Path;
use Throwable;

final class LocalAdapter implements StorageAdapterInterface
{
    private readonly string $root;

    public function __construct(
        string $root,
        private readonly int $fileMode = 0600,
        private readonly int $directoryMode = 0700,
    ) {
        $isUnixRoot    = str_starts_with($root, '/');
        $isWindowsRoot = DIRECTORY_SEPARATOR === '\\' && preg_match('~^[A-Za-z]:[/\\\\]~', $root);

        if ((!$isUnixRoot && !$isWindowsRoot) || str_contains($root, "\0") || str_contains($root, '://')) {
            throw new StorageException('The local storage root must be an absolute filesystem directory.');
        }

        $invalidFileMode      = $fileMode < 0 || $fileMode > 0777;
        $invalidDirectoryMode = $directoryMode < 0 || $directoryMode > 0777;

        if ($invalidFileMode || $invalidDirectoryMode) {
            throw new StorageException('Storage permissions must be Unix permission modes between 0000 and 0777.');
        }

        $this->root = rtrim($root, '/\\');

        if ($this->root === '' || preg_match('/^[A-Za-z]:$/', $this->root)) {
            throw new StorageException('The filesystem root cannot be used as a storage disk.');
        }
    }

    public function write(string $path, string $contents): void
    {
        $this->writeFile($path, static function ($output) use ($contents): void {
            self::writeContents($output, $contents);
        });
    }

    /** @param resource $stream */
    public function writeStream(string $path, mixed $stream): void
    {
        Path::validate($path);

        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new UnableToWriteException('Expected an open, readable PHP stream.');
        }

        $metadata   = stream_get_meta_data($stream);
        $isReadable = strpbrk($metadata['mode'], 'r+') !== false;

        if (!$isReadable) {
            throw new UnableToWriteException('Expected an open, readable PHP stream.');
        }

        $this->writeFile($path, static function ($output) use ($stream): void {
            while (!feof($stream)) {
                $chunk = fread($stream, 8192);

                if ($chunk === false || ($chunk === '' && !feof($stream))) {
                    throw new UnableToWriteException('Unable to consume the complete input stream.');
                }

                self::writeContents($output, $chunk);
            }
        });
    }

    public function read(string $path): string
    {
        $input = $this->readStream($path);

        try {
            return $this->perform(static function () use ($input, $path): string {
                $contents = stream_get_contents($input);

                if ($contents === false) {
                    throw new StorageException("Unable to read '$path'.");
                }

                return $contents;
            }, "Unable to read '$path'.");
        } finally {
            fclose($input);
        }
    }

    /** @return resource */
    public function readStream(string $path): mixed
    {
        Path::validate($path);

        return $this->perform(function () use ($path) {
            $fullPath = $this->resolve($path);

            $this->requireFile($fullPath, $path);

            $stream = fopen($fullPath, 'rb');

            if ($stream === false) {
                throw new StorageException("Unable to open '$path'.");
            }

            return $stream;
        }, "Unable to open '$path'.");
    }

    public function exists(string $path): bool
    {
        Path::validate($path);

        return $this->perform(fn() => is_file($this->resolve($path)), "Unable to check '$path'.");
    }

    public function delete(string $path): void
    {
        Path::validate($path);

        $this->perform(function () use ($path): void {
            $fullPath = $this->resolve($path);

            if (!file_exists($fullPath)) {
                return;
            }

            $this->requireFile($fullPath, $path);

            if (!unlink($fullPath)) {
                throw new StorageException("Unable to delete '$path'.");
            }
        }, "Unable to delete '$path'.");
    }

    public function move(string $source, string $destination): void
    {
        Path::validate($source);
        Path::validate($destination);

        $this->perform(function () use ($source, $destination): void {
            $sourcePath = $this->resolve($source);

            $this->requireFile($sourcePath, $source);

            if ($source === $destination) {
                return;
            }

            $destinationPath = $this->resolve($destination, true);

            if (!rename($sourcePath, $destinationPath)) {
                throw new UnableToWriteException("Unable to move '$source' to '$destination'.");
            }
        }, "Unable to move '$source' to '$destination'.", UnableToWriteException::class);
    }

    public function copy(string $source, string $destination): void
    {
        Path::validate($source);
        Path::validate($destination);

        $input = $this->readStream($source);

        try {
            // Even a same-path copy must check that the source is a regular file.
            if ($source !== $destination) {
                $this->writeStream($destination, $input);
            }
        } finally {
            fclose($input);
        }
    }

    /** @param callable(resource): void $writer */
    private function writeFile(string $path, callable $writer): void
    {
        Path::validate($path);

        $this->perform(function () use ($path, $writer): void {
            $destination = $this->resolve($path, true);
            $temporary   = dirname($destination) . '/.naf-storage-' . bin2hex(random_bytes(16));
            $output      = fopen($temporary, 'x+b');

            if ($output === false) {
                throw new UnableToWriteException("Unable to create temporary file for '$path'.");
            }

            try {
                if (!chmod($temporary, $this->fileMode)) {
                    throw new UnableToWriteException("Unable to set permissions for '$path'.");
                }

                $writer($output);

                if (!fflush($output)) {
                    throw new UnableToWriteException("Unable to flush '$path'.");
                }

                fclose($output);

                $output = null;

                // Recheck links before promotion. Never truncate an existing file:
                // a failed stream leaves its original contents intact.
                $this->resolve($path, true);

                if (!rename($temporary, $destination)) {
                    throw new UnableToWriteException("Unable to replace '$path'.");
                }
            } finally {
                if (is_resource($output)) {
                    fclose($output);
                }

                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }
        }, "Unable to write '$path'.", UnableToWriteException::class);
    }

    /** Validate every component, including dangling links and the file itself. */
    private function resolve(string $path, bool $writing = false): string
    {
        clearstatcache(true);

        if (is_link($this->root)) {
            throw new StorageException('The storage root must not be a symbolic link.');
        }

        if ($writing) {
            $this->createDirectory($this->root);
        } elseif (file_exists($this->root) && !is_dir($this->root)) {
            throw new StorageException('The storage root is not a directory.');
        }

        $segments  = explode('/', $path);
        $lastIndex = count($segments) - 1;
        $fullPath  = $this->root;

        foreach ($segments as $index => $segment) {
            // stat()/is_file() can silently report false for an inaccessible parent.
            // Do not mistake an access failure for a missing file.
            if (is_dir($fullPath)) {
                $isReadable   = is_readable($fullPath);
                $isSearchable = DIRECTORY_SEPARATOR !== '/' || is_executable($fullPath);

                if (!$isReadable || !$isSearchable) {
                    throw new StorageException('A storage directory is not accessible.');
                }
            }

            $fullPath .= '/' . $segment;

            if (is_link($fullPath)) {
                throw new StorageException('Symbolic links are not allowed inside storage disks.');
            }

            if ($index !== $lastIndex) {
                if ($writing) {
                    $this->createDirectory($fullPath);
                } elseif (file_exists($fullPath) && !is_dir($fullPath)) {
                    throw new StorageException('A storage path component is not a directory.');
                }

                continue;
            }

            if (file_exists($fullPath) && !is_file($fullPath) && ($writing || !is_dir($fullPath))) {
                throw new StorageException('Storage operations require regular files.');
            }
        }

        return $fullPath;
    }

    private function createDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (file_exists($directory) || is_link($directory)) {
            throw new UnableToWriteException('A storage directory path is occupied by a file or link.');
        }

        $parent = dirname($directory);

        if ($parent !== $directory) {
            $this->createDirectory($parent);
        }

        // Another application worker may create the same parent concurrently.
        if (!@mkdir($directory, $this->directoryMode)) {
            clearstatcache(true, $directory);

            if (is_link($directory) || !is_dir($directory)) {
                throw new UnableToWriteException('Unable to create a storage directory.');
            }

            return;
        }

        if (!chmod($directory, $this->directoryMode)) {
            throw new UnableToWriteException('Unable to set storage directory permissions.');
        }
    }

    private function requireFile(string $fullPath, string $path): void
    {
        if (!file_exists($fullPath)) {
            throw new FileNotFoundException("Storage file '$path' does not exist.");
        }

        if (!is_file($fullPath)) {
            throw new StorageException("Storage path '$path' is not a regular file.");
        }
    }

    /** @param resource $output */
    private static function writeContents(mixed $output, string $contents): void
    {
        $length = strlen($contents);

        for ($offset = 0; $offset < $length; $offset += $written) {
            $chunk   = substr($contents, $offset, 8192);
            $written = fwrite($output, $chunk);

            if ($written === false || $written === 0) {
                throw new UnableToWriteException('Unable to write complete file contents.');
            }
        }
    }

    /**
     * Translate native filesystem failures independently of HTTP/global handlers.
     *
     * @param class-string<StorageException> $exceptionClass
     */
    private function perform(
        callable $operation,
        string $message,
        string $exceptionClass = StorageException::class,
    ): mixed {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return true;
            }

            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            return $operation();
        } catch (StorageException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new $exceptionClass($message, 0, $exception);
        } finally {
            restore_error_handler();
        }
    }
}
