<?php

declare(strict_types=1);

namespace Naf\Storage;

/** Optional capability for adapters that can generate public URLs. */
interface PublicUrlProviderInterface
{
    public function url(string $path): string;
}
