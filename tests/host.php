<?php

declare(strict_types=1);

// Optional first argument: a separately installed host's Composer autoloader.
$autoload = $argv[1] ?? dirname(__DIR__) . '/vendor/autoload.php';
$host = sys_get_temp_dir() . '/naf-storage-host-' . bin2hex(random_bytes(12));
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
    file_put_contents($host . '/app/config.php', <<<'PHP'
<?php
use Naf\Storage\Adapters\LocalAdapter;
return ['storage' => [
    'default' => 'documents',
    'disks' => [
        'documents' => ['adapter' => LocalAdapter::class, 'root' => BASE_PATH . '/documents'],
        'public' => ['adapter' => LocalAdapter::class, 'root' => BASE_PATH . '/public-files', 'url' => '/storage'],
    ],
]];
PHP);
    require $autoload;
    \Naf\app()->run();
    check(\Naf\app()->hasPlugin('naf/storage'), 'Composer did not discover the plugin');
    check(\Naf\config('storage:disks:local:root') === BASE_PATH . '/storage', 'Plugin defaults were not merged');
    check(!is_dir($host . '/storage') && !is_dir($host . '/documents'), 'Bootstrap created directories eagerly');

    \Naf\Storage\storage()->put('foo.txt', 'Hello World');
    check(\Naf\Storage\storage('documents')->get('foo.txt') === 'Hello World', 'Host default disk was not selected');
    check(\Naf\Storage\storage('public')->url('avatars/123.jpg') === '/storage/avatars/123.jpg', 'Public disk URL mismatch');
    check(\Naf\app()->container()->get(\Naf\Storage\Filesystem::class) === \Naf\Storage\storage(), 'Default disk DI mismatch');

    $stream = \Naf\Storage\storage()->readStream('foo.txt');
    try {
        \Naf\Storage\storage('public')->writeStream('copied.txt', $stream);
    } finally {
        fclose($stream);
    }
    check(\Naf\Storage\storage('public')->get('copied.txt') === 'Hello World', 'Cross-disk stream copy failed');
    \Naf\Storage\storage()->delete('foo.txt');
    check(!\Naf\Storage\storage()->exists('foo.txt'), 'Delete failed');
    echo "Installed NAF host discovery, config overrides, DI and documented examples passed.\n";
} finally {
    removeHost($host);
}
