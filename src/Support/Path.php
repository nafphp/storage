<?php

declare(strict_types=1);

namespace Naf\Storage\Support;

use Naf\Storage\Exceptions\StorageException;

/** @internal Shared by the public facade and the standalone local adapter. */
final class Path
{
    public static function validate(string $path): void
    {
        if ($path === '' || preg_match('/[\x00-\x1f\x7f\\\\:]/', $path)) {
            throw new StorageException(
                'Storage paths must be relative file paths without control characters, backslashes or schemes.',
            );
        }

        $segments = explode('/', $path);

        foreach ($segments as $segment) {
            // Empty/dot segments also reject absolute paths, repeated and trailing slashes.
            // Windows device names and trailing dots/spaces are not portable file keys.
            $isDotOrEmpty = in_array($segment, ['', '.', '..'], true);
            $hasSuffix    = rtrim($segment, '. ') !== $segment;
            $isDeviceName = preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i', $segment);

            if ($isDotOrEmpty || $hasSuffix || $isDeviceName) {
                throw new StorageException('Storage paths must contain ordinary relative file names.');
            }
        }
    }
}
