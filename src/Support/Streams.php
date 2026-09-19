<?php

declare(strict_types=1);

namespace Naf\Storage\Support;

use ErrorException;
use Naf\Storage\Exceptions\StorageException;
use Naf\Storage\Exceptions\UnableToWriteException;
use Psr\Http\Message\StreamInterface;
use Throwable;

/** @internal Bounded temporary storage for replayable remote uploads and PHP stream ownership. */
final class Streams
{
    /** @return resource */
    public static function snapshot(mixed $input): mixed
    {
        if (!is_resource($input) || get_resource_type($input) !== 'stream') {
            throw new UnableToWriteException('A readable PHP stream resource is required.');
        }

        $output = null;

        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            $output = tmpfile();

            if ($output === false) {
                throw new UnableToWriteException('Cannot create the temporary upload stream.');
            }

            while (!feof($input)) {
                $chunk = fread($input, 65536);

                if ($chunk === false || ($chunk === '' && !feof($input))) {
                    throw new UnableToWriteException('The upload stream failed or stalled before EOF.');
                }

                $offset = 0;
                $length = strlen($chunk);

                while ($offset < $length) {
                    $written = fwrite($output, substr($chunk, $offset));

                    if ($written === false || $written === 0) {
                        throw new UnableToWriteException('Cannot write the temporary upload stream.');
                    }

                    $offset += $written;
                }
            }

            if (!rewind($output)) {
                throw new UnableToWriteException('Cannot rewind the temporary upload stream.');
            }

            return $output;
        } catch (Throwable $exception) {
            if (is_resource($output)) {
                fclose($output);
            }

            throw new UnableToWriteException('Cannot prepare the upload stream.', 0, $exception);
        } finally {
            restore_error_handler();
        }
    }

    /** @return resource The caller takes ownership; non-native PSR streams are copied in chunks. */
    public static function resource(StreamInterface $body): mixed
    {
        // PSR-7 detach() may return null for custom streams. Bounded copying
        // also supports implementations with no detachable native resource.
        $output = @tmpfile();

        if ($output === false) {
            $body->close();
            throw new StorageException('Cannot create the download stream.');
        }

        try {
            if ($body->isSeekable()) {
                $body->rewind();
            }

            while (!$body->eof()) {
                $chunk = $body->read(65536);

                if (($chunk === '' && !$body->eof()) || @fwrite($output, $chunk) !== strlen($chunk)) {
                    throw new StorageException('Cannot copy the download stream.');
                }
            }

            if (!rewind($output)) {
                throw new StorageException('Cannot rewind the download stream.');
            }

            return $output;
        } catch (Throwable $exception) {
            fclose($output);

            throw new StorageException('Cannot read the download stream.', 0, $exception);
        } finally {
            $body->close();
        }
    }
}
