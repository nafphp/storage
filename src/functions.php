<?php

declare(strict_types=1);

namespace Naf\Storage;

use function Naf\app;

/** Retrieve the configured default disk or a named disk. */
function storage(?string $name = null): Storage
{
    return app()->container()->get(StorageManager::class)->disk($name);
}
