<?php

declare(strict_types=1);

namespace Tests\Unit;

use Naf\Core\Config;
use Naf\Core\Container;
use Naf\Decorators\AutoResolvingContainer;
use Naf\Storage\Adapters\S3Adapter;
use Naf\Storage\Exceptions\FileNotFoundException;
use Naf\Storage\Exceptions\StorageException;
use Naf\Storage\Exceptions\UnableToWriteException;
use Naf\Storage\Storage;
use Naf\Storage\StorageManager;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Client\ClientInterface;
use Tests\Fixtures\RecordingHttpClient;
use Tests\NafTestCase;

final class S3AdapterTest extends NafTestCase
{
    private function adapter(RecordingHttpClient $http, string $prefix = 'private'): S3Adapter
    {
        return new S3Adapter('test-bucket', 'us-east-1', 'test-access', 'test-secret', 'https://s3.example.test', true, $prefix, 'test-token', $http);
    }

    public function testSignedUploadUsesInjectedHttpClientAndKeepsInputPositionAndOwnership(): void
    {
        $http    = new RecordingHttpClient(static fn() => new Response(200, ['ETag' => '"etag"']));
        $adapter = $this->adapter($http);
        $stream  = fopen('php://temp', 'w+b');

        fwrite($stream, 'skipHello World');
        fseek($stream, 4);

        try {
            $adapter->writeStream('nested/a #%.txt', $stream);

            self::assertSame(['Hello World'], $http->bodies);
            self::assertTrue(is_resource($stream));
            self::assertSame(15, ftell($stream));
            self::assertSame('/test-bucket/private/nested/a%20%23%25.txt', $http->requests[0]->getUri()->getPath());
            self::assertStringStartsWith('AWS4-HMAC-SHA256 ', $http->requests[0]->getHeaderLine('Authorization'));
            self::assertSame('test-token', $http->requests[0]->getHeaderLine('X-Amz-Security-Token'));
            self::assertFalse($http->requests[0]->hasHeader('X-Amz-Acl'));
        } finally {
            fclose($stream);
        }
    }

    public function testNonSeekableAndStringWrites(): void
    {
        $http    = new RecordingHttpClient(static fn() => new Response(200));
        $adapter = $this->adapter($http);

        [$writer, $reader] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        fwrite($writer, 'socket');
        fclose($writer);

        try {
            $adapter->writeStream('socket', $reader);
            $adapter->write('socket', 'overwritten');
            $adapter->write('empty', '');

            self::assertSame(['socket', 'overwritten', ''], $http->bodies);
            self::assertTrue(is_resource($reader));
        } finally {
            fclose($reader);
        }
    }

    public function testReadsExistsDeleteAndPublicUrl(): void
    {
        $http = new RecordingHttpClient(static fn($request) => match ($request->getMethod()) {
            'GET'    => new Response(200, ['Content-Length' => '5'], 'hello'),
            'HEAD'   => new Response(200, ['Content-Length' => '5']),
            'DELETE' => new Response(204),
        });
        $storage = new Storage($this->adapter($http), 'https://cdn.example.test/private');
        $stream  = $storage->readStream('test.txt');

        try {
            self::assertSame(0, ftell($stream));
            self::assertSame('hello', stream_get_contents($stream));
            self::assertSame('hello', $storage->get('test.txt'));
            self::assertTrue($storage->exists('test.txt'));

            $storage->delete('test.txt');

            self::assertSame('https://cdn.example.test/private/a%20b.txt', $storage->url('a b.txt'));
        } finally {
            fclose($stream);
        }
    }

    public function testServerSideCopyAndMove(): void
    {
        $http = new RecordingHttpClient(static fn($request) => match ($request->getMethod()) {
            'HEAD'   => new Response(200, ['Content-Length' => '5']),
            'PUT'    => new Response(200, [], '<CopyObjectResult><ETag>"etag"</ETag><LastModified>2026-01-01T00:00:00Z</LastModified></CopyObjectResult>'),
            'DELETE' => new Response(204),
        });
        $adapter = $this->adapter($http);

        $adapter->copy('a #%.txt', 'nested/b.txt');
        $adapter->move('nested/b.txt', 'c.txt');
        $adapter->copy('c.txt', 'c.txt');

        self::assertSame(['HEAD', 'PUT', 'HEAD', 'PUT', 'DELETE', 'HEAD'], array_map(static fn($r) => $r->getMethod(), $http->requests));
        self::assertSame('/test-bucket/private%2Fa%20%23%25.txt', $http->requests[1]->getHeaderLine('X-Amz-Copy-Source'));
        self::assertFalse($http->requests[1]->hasHeader('X-Amz-Acl'));
    }

    public function testMissingAndForbiddenAreDifferent(): void
    {
        $http    = new RecordingHttpClient(static fn() => new Response(404, [], '<Error><Code>NoSuchKey</Code></Error>'));
        $adapter = $this->adapter($http);

        self::assertFalse($adapter->exists('missing'));

        foreach (['read', 'copy', 'move'] as $operation) {
            try {
                $adapter->$operation('missing', 'destination');
                self::fail('Missing files must fail.');
            } catch (FileNotFoundException $exception) {
                self::assertNotNull($exception->getPrevious());
            }
        }

        $http->handler = static fn() => new Response(403, [], '<Error><Code>AccessDenied</Code></Error>');

        $this->expectException(StorageException::class);
        $adapter->exists('forbidden');
    }

    public function testMultipartUploadAndAbortOnFailure(): void
    {
        $http = new RecordingHttpClient(static function ($request) {
            $query = $request->getUri()->getQuery();

            if ($request->getMethod() === 'POST' && str_contains($query, 'uploads')) {
                return new Response(200, [], '<InitiateMultipartUploadResult><Bucket>test-bucket</Bucket><Key>private/large</Key><UploadId>upload-id</UploadId></InitiateMultipartUploadResult>');
            }

            if ($request->getMethod() === 'PUT') {
                return new Response(403, [], '<Error><Code>AccessDenied</Code></Error>');
            }

            return new Response(204);
        });
        $adapter = $this->adapter($http);
        $stream  = tmpfile();

        ftruncate($stream, 17 * 1024 * 1024);

        try {
            $adapter->writeStream('large', $stream);
            self::fail('Multipart upload should fail.');
        } catch (UnableToWriteException $exception) {
            $last = $http->requests[array_key_last($http->requests)];

            self::assertSame('DELETE', $last->getMethod());
            self::assertStringContainsString('uploadId=upload-id', $last->getUri()->getQuery());
            self::assertTrue(is_resource($stream));
        } finally {
            fclose($stream);
        }
    }

    public function testDisksUseNormalContainerInjectionAndSeparatePrefixes(): void
    {
        $http      = new RecordingHttpClient(static fn() => new Response(200));
        $container = new AutoResolvingContainer(new Container());
        $options   = ['adapter' => S3Adapter::class, 'bucket' => 'test-bucket', 'region' => 'us-east-1', 'accessKey' => 'key', 'secretKey' => 'secret'];
        $config    = new Config(['storage' => ['default' => 'one', 'disks' => ['one' => $options + ['prefix' => 'one'], 'two' => $options + ['prefix' => 'two']]]]);

        $container->set(ClientInterface::class, $http);

        $manager = new StorageManager($config, $container);

        $manager->disk()->put('file', 'one');
        $manager->disk('two')->put('file', 'two');

        self::assertSame('/one/file', $http->requests[0]->getUri()->getPath());
        self::assertSame('/two/file', $http->requests[1]->getUri()->getPath());
    }

    #[DataProvider('invalidPaths')]
    public function testInvalidPathsNeverReachTheNetwork(string $path): void
    {
        $http    = new RecordingHttpClient(static fn() => new Response(200));
        $adapter = $this->adapter($http);

        try {
            $adapter->write($path, 'unsafe');
            self::fail('Unsafe path accepted.');
        } catch (StorageException $exception) {
            self::assertSame([], $http->requests);
        }
    }

    public static function invalidPaths(): iterable
    {
        foreach (['../x', '/absolute', 'a/../../x', 'C:\\x', 's3://x', "null\0byte"] as $path) {
            yield [$path];
        }
    }
}
