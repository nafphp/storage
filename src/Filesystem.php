<?php

declare(strict_types=1);

namespace Naf\Storage;

use Naf\Storage\Exceptions\StorageException;
use Naf\Storage\Exceptions\UnableToWriteException;
use Naf\Storage\Support\Path;

final class Filesystem
{
    public function __construct(
        private readonly StorageAdapterInterface $adapter,
        private readonly ?string $url = null,
    ) {
        if ($url !== null) {
            $parts = parse_url($url);
            $relative = str_starts_with($url, '/') && !str_starts_with($url, '//');
            $absolute = is_array($parts) && isset($parts['host'])
                && in_array($parts['scheme'] ?? null, ['http', 'https'], true);

            if ($url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url)
                || $parts === false || (!$relative && !$absolute)
                || isset($parts['query']) || isset($parts['fragment'])
                || isset($parts['user']) || isset($parts['pass'])
            ) {
                throw new StorageException('A public storage URL must be a root-relative path or an HTTP(S) URL without credentials, query or fragment.');
            }
        }
    }

    public function put(string $path, string $contents): void
    {
        $this->adapter->write(Path::validate($path), $contents);
    }

    public function get(string $path): string
    {
        return $this->adapter->read(Path::validate($path));
    }

    /** @param resource $stream Readable PHP stream; consumed from its current position, left open. */
    public function writeStream(string $path, mixed $stream): void
    {
        Path::validate($path);
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream'
            || strpbrk(stream_get_meta_data($stream)['mode'], 'r+') === false
        ) {
            throw new UnableToWriteException('Expected an open, readable PHP stream.');
        }

        $this->adapter->writeStream($path, $stream);
    }

    /** @return resource Readable PHP stream; the caller must close it. */
    public function readStream(string $path): mixed
    {
        return $this->adapter->readStream(Path::validate($path));
    }

    public function exists(string $path): bool
    {
        return $this->adapter->exists(Path::validate($path));
    }

    public function delete(string $path): void
    {
        $this->adapter->delete(Path::validate($path));
    }

    public function move(string $source, string $destination): void
    {
        $this->adapter->move(Path::validate($source), Path::validate($destination));
    }

    public function copy(string $source, string $destination): void
    {
        $this->adapter->copy(Path::validate($source), Path::validate($destination));
    }

    /** Generate a public URL without checking file existence or publishing anything. */
    public function url(string $path): string
    {
        Path::validate($path);

        if ($this->url !== null) {
            return rtrim($this->url, '/') . '/' . implode('/', array_map(rawurlencode(...), explode('/', $path)));
        }

        if ($this->adapter instanceof PublicUrlProviderInterface) {
            return $this->adapter->url($path);
        }

        throw new StorageException('This storage disk cannot generate public URLs.');
    }
}
