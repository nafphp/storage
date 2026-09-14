<?php

declare(strict_types=1);

use Naf\Storage\Adapters\LocalAdapter;

return [
    'storage' => [
        'default' => 'local',
        'disks' => [
            'local' => [
                'adapter' => LocalAdapter::class,
                'root' => BASE_PATH . '/storage',
            ],
        ],
    ],
];
