<?php

declare(strict_types=1);

use Naf\Core\Config;
use Naf\Storage\Storage;
use Naf\Storage\StorageManager;
use function Naf\app;

$container = app()->container();

$container->set(StorageManager::class, static fn() => new StorageManager(
    $container->get(Config::class),
    $container,
));

$container->set(Storage::class, static fn() => $container->get(StorageManager::class)->disk());
