<?php

declare(strict_types=1);

use Naf\Storage\Storage;

use function Naf\app;
use function Naf\config;
use function Naf\Storage\storage;

// Optional first argument: a separately installed host's Composer autoloader.
$autoload = $argv[1] ?? dirname(__DIR__) . '/vendor/autoload.php';
$host     = sys_get_temp_dir() . '/naf-storage-host-' . bin2hex(random_bytes(12));

mkdir($host . '/app', 0700, true);
define('BASE_PATH', $host);

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function removeHost(string $path): void
{
    foreach (new FilesystemIterator($path) as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            removeHost($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }

    rmdir($path);
}

try {
    file_put_contents($host . '/app/config.php', <<<'CONFIG'
    <?php

    use Naf\Storage\Adapters\LocalAdapter;

    return [
        'storage' => [
            'default' => 'documents',
            'disks'   => [
                'documents' => [
                    'adapter' => LocalAdapter::class,
                    'root'    => BASE_PATH . '/documents',
                ],
                'public' => [
                    'adapter' => LocalAdapter::class,
                    'root'    => BASE_PATH . '/public-files',
                    'url'     => '/storage',
                ],
            ],
        ],
    ];
    CONFIG);

    require $autoload;

    app()->run();

    check(app()->hasPlugin('naf/storage'), 'Composer did not discover the plugin');
    check(config('storage:disks:local:root') === BASE_PATH . '/storage', 'Plugin defaults were not merged');
    check(!is_dir($host . '/storage') && !is_dir($host . '/documents'), 'Bootstrap created directories eagerly');

    storage()->put('foo.txt', 'Hello World');

    $contents = storage('documents')->get('foo.txt');
    $url      = storage('public')->url('avatars/123.jpg');
    $injected = app()->container()->get(Storage::class);

    check($contents === 'Hello World', 'Host default disk was not selected');
    check($url === '/storage/avatars/123.jpg', 'Public disk URL mismatch');
    check($injected === storage(), 'Default disk DI mismatch');

    $stream = storage()->readStream('foo.txt');

    try {
        storage('public')->writeStream('copied.txt', $stream);
    } finally {
        fclose($stream);
    }

    check(storage('public')->get('copied.txt') === 'Hello World', 'Cross-disk stream copy failed');

    storage()->delete('foo.txt');

    check(!storage()->exists('foo.txt'), 'Delete failed');

    echo "Installed NAF host discovery, config overrides, DI and documented examples passed.\n";
} finally {
    removeHost($host);
}
