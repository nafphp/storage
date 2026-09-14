<?php

declare(strict_types=1);

namespace Naf\Storage;

use Naf\Storage\Exceptions\StorageException;
use Naf\Storage\Support\Path;

/** The application-facing API for one disk; file I/O belongs to its adapter. */
final class Storage
{
    public function __construct(
        private readonly StorageAdapterInterface $adapter,
        private readonly ?string $url = null,
    ) {
        if ($url === null) {
            return;
        }

        $parts      = parse_url($url);
        $isRelative = str_starts_with($url, '/') && !str_starts_with($url, '//');
        $isAbsolute = is_array($parts)
            && isset($parts['host'])
            && in_array($parts['scheme'] ?? null, ['http', 'https'], true);

        $hasInvalidCharacters = preg_match('/[\x00-\x20\x7f\\\\]/', $url);
        $hasCredentials       = isset($parts['user']) || isset($parts['pass']);
        $hasQueryOrFragment   = isset($parts['query']) || isset($parts['fragment']);

        if (
            $url === ''
            || $parts === false
            || (!$isRelative && !$isAbsolute)
            || $hasInvalidCharacters
            || $hasCredentials
            || $hasQueryOrFragment
        ) {
            throw new StorageException(
                'A public storage URL must be a root-relative path or an HTTP(S) URL '
                . 'without credentials, query or fragment.'
            );
        }
    }

    public function put(string $path, string $contents): void
    {
        Path::validate($path);

        $this->adapter->write($path, $contents);
    }

    public function get(string $path): string
    {
        Path::validate($path);

        return $this->adapter->read($path);
    }

    /** @param resource $stream Readable PHP stream; consumed from its current position, left open. */
    public function writeStream(string $path, mixed $stream): void
    {
        Path::validate($path);

        $this->adapter->writeStream($path, $stream);
    }

    /** @return resource Readable PHP stream; the caller must close it. */
    public function readStream(string $path): mixed
    {
        Path::validate($path);

        return $this->adapter->readStream($path);
    }

    public function exists(string $path): bool
    {
        Path::validate($path);

        return $this->adapter->exists($path);
    }

    public function delete(string $path): void
    {
        Path::validate($path);

        $this->adapter->delete($path);
    }

    public function move(string $source, string $destination): void
    {
        Path::validate($source);
        Path::validate($destination);

        $this->adapter->move($source, $destination);
    }

    public function copy(string $source, string $destination): void
    {
        Path::validate($source);
        Path::validate($destination);

        $this->adapter->copy($source, $destination);
    }

    /** Generate a public URL without checking file existence or publishing anything. */
    public function url(string $path): string
    {
        Path::validate($path);

        if ($this->url !== null) {
            $prefix   = rtrim($this->url, '/');
            $segments = explode('/', $path);
            $encoded  = array_map(rawurlencode(...), $segments);

            return $prefix . '/' . implode('/', $encoded);
        }

        if ($this->adapter instanceof PublicUrlProviderInterface) {
            return $this->adapter->url($path);
        }

        throw new StorageException('This storage disk cannot generate public URLs.');
    }
}
