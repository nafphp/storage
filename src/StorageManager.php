<?php

declare(strict_types=1);

namespace Naf\Storage;

use Naf\Core\Config;
use Naf\Decorators\AutoResolvingContainer;
use Naf\Storage\Exceptions\StorageException;
use Throwable;

/** Select and lazily create the disks declared in NAF configuration. */
final class StorageManager
{
    /** @var array<string, Storage> */
    private array $disks = [];

    public function __construct(
        private readonly Config $config,
        private readonly AutoResolvingContainer $container,
    ) {
    }

    public function disk(?string $name = null): Storage
    {
        $config = $this->config->get('storage');

        if (!is_array($config)) {
            throw new StorageException('storage must be a configuration array.');
        }

        $name ??= $config['default'] ?? null;

        if (!is_string($name) || trim($name) === '') {
            throw new StorageException('A non-empty disk name or storage:default is required.');
        }

        if (isset($this->disks[$name])) {
            return $this->disks[$name];
        }

        $disks   = $config['disks'] ?? null;
        $options = is_array($disks) ? ($disks[$name] ?? null) : null;

        if (!is_array($options)) {
            throw new StorageException("Storage disk '$name' is missing or is not a configuration array.");
        }

        $class = $options['adapter'] ?? null;

        if (!is_string($class) || !is_subclass_of($class, StorageAdapterInterface::class)) {
            throw new StorageException(
                "Storage disk '$name' must name an adapter implementing " . StorageAdapterInterface::class . '.',
            );
        }

        $url = $options['url'] ?? null;

        if ($url !== null && !is_string($url)) {
            throw new StorageException("Storage disk '$name' must have a string or null URL.");
        }

        unset($options['adapter'], $options['url']);

        try {
            // A fresh adapter per disk: a shared class binding would mix roots/options.
            // NAF resolves constructor dependencies and the explicit named options.
            $adapter = $this->container->make($class, $options);
            $storage = new Storage($adapter, $url);

            $this->disks[$name] = $storage;

            return $storage;
        } catch (Throwable $exception) {
            throw new StorageException("Cannot configure storage disk '$name'.", 0, $exception);
        }
    }
}
