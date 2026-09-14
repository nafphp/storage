<?php

declare(strict_types=1);

namespace Naf\Storage;

/**
 * File operations on relative, slash-separated storage paths.
 *
 * Implementations throw Exceptions\StorageException on failure, overwrite files
 * on write/copy/move, and treat deleting a missing file as a successful no-op.
 * Missing read/copy/move sources throw Exceptions\FileNotFoundException.
 */
interface StorageAdapterInterface
{
    public function write(string $path, string $contents): void;

    /**
     * Consume a readable stream from its current position; never close or rewind it.
     *
     * @param resource $stream Readable PHP stream, including non-seekable streams.
     */
    public function writeStream(string $path, mixed $stream): void;

    public function read(string $path): string;

    /** @return resource Readable PHP stream at the beginning; the caller must close it. */
    public function readStream(string $path): mixed;

    /** Whether a file exists, not a directory. */
    public function exists(string $path): bool;

    public function delete(string $path): void;

    public function move(string $source, string $destination): void;

    public function copy(string $source, string $destination): void;
}
