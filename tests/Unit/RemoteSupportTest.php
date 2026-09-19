<?php

declare(strict_types=1);

namespace Tests\Unit;

use Naf\Storage\Adapters\S3Adapter;
use Naf\Storage\Adapters\WebDavAdapter;
use Naf\Storage\Exceptions\StorageException;
use Naf\Storage\Exceptions\UnableToWriteException;
use Naf\Storage\Support\Streams;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\RecordingHttpClient;
use Tests\NafTestCase;

final class RemoteSupportTest extends NafTestCase
{
    #[DataProvider('invalidConfiguration')]
    public function testInvalidConfigurationFailsBeforeIo(string $adapter, array $options): void
    {
        $http = new RecordingHttpClient(static fn() => new Response(200));

        $this->expectException(StorageException::class);

        if ($adapter === 's3') {
            new S3Adapter(...array_replace(['bucket' => 'test-bucket', 'region' => 'us-east-1', 'accessKey' => 'key', 'secretKey' => 'secret', 'client' => $http], $options));
        } else {
            new WebDavAdapter(...array_replace(['endpoint' => 'https://dav.example.test/files', 'username' => 'user', 'password' => 'secret', 'client' => $http], $options));
        }
    }

    public static function invalidConfiguration(): iterable
    {
        foreach (['s3', 'dav'] as $adapter) {
            foreach (['file:///etc', 'https://user:pass@example.test/', 'https://example.test/?token=secret', 'https://example.test/#x', "https://example.test/\r\n", 'https://example.test/%2e%2e/path'] as $endpoint) {
                yield [$adapter, ['endpoint' => $endpoint]];
            }
        }

        yield ['s3', ['bucket' => '../bucket']];
        yield ['s3', ['region' => '']];
        yield ['s3', ['accessKey' => '']];
        yield ['s3', ['secretKey' => '']];
        yield ['s3', ['prefix' => '../outside']];
        yield ['dav', ['username' => 'user:password']];
    }

    public function testClosedNonStreamAndWriteOnlyStreamsFailWithoutWarnings(): void
    {
        $closed = fopen('php://temp', 'w+b');
        fclose($closed);

        $writeOnly = fopen($this->directory . '/output', 'wb');

        try {
            foreach ([null, 'contents', $closed, $writeOnly] as $input) {
                try {
                    Streams::snapshot($input);
                    self::fail('Invalid stream was accepted.');
                } catch (UnableToWriteException $exception) {
                    self::assertNotSame('', $exception->getMessage());
                }
            }
        } finally {
            fclose($writeOnly);
        }
    }

    public function testStalledStreamFailsAndStaysOpen(): void
    {
        [$writer, $reader] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        stream_set_blocking($reader, false);

        try {
            Streams::snapshot($reader);
            self::fail('Stalled stream was accepted.');
        } catch (UnableToWriteException $exception) {
            self::assertTrue(is_resource($reader));
        } finally {
            fclose($reader);
            fclose($writer);
        }
    }
}
