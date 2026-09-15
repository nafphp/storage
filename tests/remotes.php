<?php

declare(strict_types=1);

// Explicit opt-in integration harness. Only use disposable, loopback endpoints.
define('BASE_PATH', __DIR__ . '/Fixtures');
$autoload   = getenv('NAF_TEST_AUTOLOAD') ?: dirname(__DIR__) . '/vendor/autoload.php';
$loader     = require $autoload;
$clientPath = $argv[1] ?? dirname(__DIR__, 2) . '/client';
$loader->addPsr4('Naf\\Client\\', $clientPath . '/src/');

use Aws\S3\S3Client;
use Naf\Client\Core\Client;
use Naf\Storage\Adapters\S3Adapter;
use Naf\Storage\Adapters\WebDavAdapter;
use Naf\Storage\Exceptions\FileNotFoundException;
use Naf\Storage\Exceptions\StorageException;
use Naf\Storage\Storage;
use Naf\Storage\Support\S3HttpHandler;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$s3Endpoint  = getenv('NAF_TEST_S3_ENDPOINT');
$davEndpoint = getenv('NAF_TEST_DAV_ENDPOINT');

foreach ([$s3Endpoint, $davEndpoint] as $endpoint) {
    if (!$endpoint || parse_url($endpoint, PHP_URL_HOST) !== '127.0.0.1') {
        throw new RuntimeException('Set NAF_TEST_S3_ENDPOINT and NAF_TEST_DAV_ENDPOINT to disposable loopback servers.');
    }
}

$http     = (new Client())->withOptions(['streaming' => true, 'retries' => 0, 'max_redirects' => 0]);
$user     = 'naf-storage-test';
$password = 'naf-storage-test-password';
$bucket   = 'naf-test-' . bin2hex(random_bytes(8));
$sdk      = new S3Client([
    'version'                 => '2006-03-01', 'region' => 'us-east-1', 'endpoint' => $s3Endpoint,
    'use_path_style_endpoint' => true, 'credentials' => ['key' => $user, 'secret' => $password],
    'http_handler'            => new S3HttpHandler($http),
]);

$sdk->createBucket(['Bucket' => $bucket]);

$adapters = [
    'S3'     => new S3Adapter($bucket, 'us-east-1', $user, $password, $s3Endpoint, true, client: $http),
    'WebDAV' => new WebDavAdapter($davEndpoint, $user, $password, $http),
];

try {
    foreach ($adapters as $name => $adapter) {
        $storage = new Storage($adapter);
        $prefix  = 'contract-' . bin2hex(random_bytes(8));
        $first   = $prefix . '/nested/ä #%.txt';
        $copy    = $prefix . '/deep/copied';
        $moved   = $prefix . '/deeper/moved';
        $large   = $prefix . '/large.bin';
        $stream  = tmpfile();
        $files   = [$first, $copy, $moved, $large, $prefix . '/socket'];

        try {
            check(!$storage->exists($first), "$name missing exists");
            $storage->delete($first);
            $storage->put($first, 'Hello World');
            check($storage->get($first) === 'Hello World', "$name write/read");
            $storage->put($first, 'replacement');
            check($storage->get($first) === 'replacement', "$name overwrite");
            $storage->copy($first, $copy);
            check($storage->get($copy) === 'replacement', "$name copy");
            $storage->put($moved, 'old');
            $storage->move($copy, $moved);
            check(!$storage->exists($copy) && $storage->get($moved) === 'replacement', "$name move overwrite");
            $storage->move($moved, $moved);

            fwrite($stream, 'skip');
            for ($i = 0; $i < 512; $i++) {
                fwrite($stream, str_repeat('x', 65536));
            }
            fseek($stream, 4);

            memory_reset_peak_usage();
            $before = memory_get_usage(true);
            $storage->writeStream($large, $stream);
            $read   = $storage->readStream($large);
            $digest = hash_init('sha256');

            try {
                check(ftell($read) === 0, "$name download position");
                check(hash_update_stream($digest, $read) === 32 * 1024 * 1024, "$name large download length");
                fseek($stream, 4);
                $expected = hash_init('sha256');
                hash_update_stream($expected, $stream);
                check(hash_final($digest) === hash_final($expected), "$name large download checksum");
                check(memory_get_peak_usage(true) - $before < 16 * 1024 * 1024, "$name bounded memory");
            } finally {
                fclose($read);
            }

            [$writer, $reader] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            fwrite($writer, 'socket');
            fclose($writer);

            try {
                $storage->writeStream($prefix . '/socket', $reader);
                check($storage->get($prefix . '/socket') === 'socket', "$name non-seekable upload");
                check(is_resource($reader), "$name input ownership");
            } finally {
                fclose($reader);
            }

            try {
                $storage->url($first);
                throw new RuntimeException("$name exposed a private URL");
            } catch (StorageException) {
            }

            $storage->delete($first);

            try {
                $storage->get($first);
                throw new RuntimeException("$name returned a missing file");
            } catch (FileNotFoundException) {
            }

            echo "$name: operations, nested/encoded paths, overwrites, 32 MiB streams, bounded memory and private URLs passed.\n";
        } finally {
            fclose($stream);
            foreach ($files as $file) {
                $storage->delete($file);
            }
        }
    }
} finally {
    // The test server/container owns any empty WebDAV collections; it is removed by the runner.
    foreach ($sdk->getPaginator('ListObjectsV2', ['Bucket' => $bucket]) as $page) {
        foreach ($page['Contents'] ?? [] as $object) {
            $sdk->deleteObject(['Bucket' => $bucket, 'Key' => $object['Key']]);
        }
    }
    $sdk->deleteBucket(['Bucket' => $bucket]);
}
