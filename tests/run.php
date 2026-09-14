<?php

declare(strict_types=1);
require getenv('NAF_TEST_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
function check(bool $value, string $message): void
{
    if (!$value) {
        throw new RuntimeException($message);
    }
}

use Naf\Storage\LocalStorage;
use Nyholm\Psr7\{Stream,UploadedFile};

$root = sys_get_temp_dir().'/naf-storage-tests-'.bin2hex(random_bytes(8));
$store = new LocalStorage($root);
$file = fn ($body, $name = 'note.txt') => new UploadedFile(Stream::create($body), strlen($body), UPLOAD_ERR_OK, $name);
try {
    $staged = $store->stage($file('A private test.', '../../note.txt'));
    check($staged['name'] === 'note.txt', 'name not sanitized');
    $store->promote($staged['key']);
    $store->promote($staged['key']);
    $in = $store->open($staged['key']);
    check(stream_get_contents($in) === 'A private test.', 'content mismatch');
    fclose($in);
    $store->delete($staged['key']);
    $store->delete($staged['key']);
    foreach ([fn () => $store->open('../escape'),fn () => $store->stage($file('Fake PDF', 'fake.pdf')),fn () => $store->stage($file('php', 'evil.php')),fn () => $store->stage($file('123456'), 3)] as $invalid) {
        try {
            $invalid();
            throw new RuntimeException('Invalid input accepted');
        } catch (InvalidArgumentException) {
        }
    }
    $orphan = $store->stage($file('Old orphan.'));
    check($store->cleanup(fn () => true, time() + 1) === 0, 'referenced file removed');
    check($store->cleanup(fn () => false, time() + 1) === 1, 'orphan not cleaned');
    echo "Storage contract cases passed.\n";
} finally {
    foreach (['ready','staging'] as $dir) {
        foreach (glob($root.'/'.$dir.'/*') ?: [] as $path) {
            unlink($path);
        }rmdir($root.'/'.$dir);
    }rmdir($root);
}
