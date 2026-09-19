<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Naf\Storage\Exceptions\FileNotFoundException;
use Naf\Storage\StorageAdapterInterface;
use stdClass;

class RecordingAdapter implements StorageAdapterInterface
{
    private array $files = [];

    public function __construct(public string $bucket, public stdClass $dependency)
    {
        $dependency->constructed++;
    }

    public function write(string $path, string $contents): void
    {
        $this->files[$path] = $contents;
    }

    public function writeStream(string $path, mixed $stream): void
    {
        $this->write($path, stream_get_contents($stream));
    }

    public function read(string $path): string
    {
        return $this->files[$path] ?? throw new FileNotFoundException($path);
    }

    public function readStream(string $path): mixed
    {
        $stream = fopen('php://temp', 'w+b');

        fwrite($stream, $this->read($path));
        rewind($stream);

        return $stream;
    }

    public function exists(string $path): bool
    {
        return array_key_exists($path, $this->files);
    }

    public function delete(string $path): void
    {
        unset($this->files[$path]);
    }

    public function move(string $source, string $destination): void
    {
        $this->copy($source, $destination);

        if ($source !== $destination) {
            $this->delete($source);
        }
    }

    public function copy(string $source, string $destination): void
    {
        $this->write($destination, $this->read($source));
    }
}
