<?php

declare(strict_types=1);

namespace Tests\Unit;

use Naf\Storage\Adapters\WebDavAdapter;
use Naf\Storage\Exceptions\FileNotFoundException;
use Naf\Storage\Exceptions\StorageException;
use Naf\Storage\Exceptions\UnableToWriteException;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\RecordingHttpClient;
use Tests\NafTestCase;

final class WebDavAdapterTest extends NafTestCase
{
    private function adapter(RecordingHttpClient $http): WebDavAdapter
    {
        return new WebDavAdapter('https://dav.example.test/files/', 'user', 'secret', $http);
    }

    private static function properties(string $path, bool $collection = false): Response
    {
        $href = htmlspecialchars($path, ENT_XML1);
        $type = $collection ? '<d:collection/>' : '';
        $xml  = '<d:multistatus xmlns:d="DAV:"><d:response><d:href>' . $href . '</d:href>'
            . '<d:propstat><d:prop><d:resourcetype>' . $type . '</d:resourcetype></d:prop>'
            . '<d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>';

        return new Response(207, [], $xml);
    }

    public function testStreamUploadCreatesParentsAndEncodesPathSegments(): void
    {
        $http = new RecordingHttpClient(static fn($request) => match ($request->getMethod()) {
            'PROPFIND' => new Response(404),
            'MKCOL'    => new Response(201),
            'PUT'      => new Response(201),
        });
        $stream = fopen('php://temp', 'w+b');

        fwrite($stream, 'skipcontents');
        fseek($stream, 4);

        try {
            $this->adapter($http)->writeStream('nested/deep/a #%.txt', $stream);

            self::assertSame(['PROPFIND', 'PROPFIND', 'MKCOL', 'PROPFIND', 'MKCOL', 'PUT'], array_map(static fn($r) => $r->getMethod(), $http->requests));
            self::assertSame('contents', $http->bodies[5]);
            self::assertSame('/files/nested/deep/a%20%23%25.txt', $http->requests[5]->getUri()->getPath());
            self::assertSame('Basic ' . base64_encode('user:secret'), $http->requests[5]->getHeaderLine('Authorization'));
            self::assertTrue(is_resource($stream));
            self::assertSame(12, ftell($stream));
        } finally {
            fclose($stream);
        }
    }

    public function testReadExistsOverwriteCopyMoveAndDelete(): void
    {
        $http = new RecordingHttpClient(static fn($request) => match ($request->getMethod()) {
            'PROPFIND'               => self::properties($request->getUri()->getPath()),
            'GET'                    => new Response(200, [], 'hello'),
            'PUT'                    => new Response(204),
            'COPY', 'MOVE', 'DELETE' => new Response(204),
        });
        $adapter = $this->adapter($http);
        $stream  = $adapter->readStream('file');

        try {
            self::assertSame(0, ftell($stream));
            self::assertSame('hello', stream_get_contents($stream));
            self::assertSame('hello', $adapter->read('file'));
            self::assertTrue($adapter->exists('file'));

            $adapter->write('file', 'replaced');
            $adapter->copy('file', 'copied');
            $adapter->move('copied', 'moved');
            $adapter->move('moved', 'moved');
            $adapter->delete('file');

            $transfers = array_values(array_filter($http->requests, static fn($r) => in_array($r->getMethod(), ['COPY', 'MOVE'])));

            self::assertCount(2, $transfers);
            self::assertSame('https://dav.example.test/files/copied', $transfers[0]->getHeaderLine('Destination'));
            self::assertSame('T', $transfers[0]->getHeaderLine('Overwrite'));
            self::assertSame('0', $transfers[0]->getHeaderLine('Depth'));
            self::assertSame('', $transfers[1]->getHeaderLine('Depth'));
        } finally {
            fclose($stream);
        }
    }

    public function testMissingDeleteIsIdempotentAndReadFails(): void
    {
        $http    = new RecordingHttpClient(static fn() => new Response(404));
        $adapter = $this->adapter($http);

        self::assertFalse($adapter->exists('missing'));

        $adapter->delete('missing');

        self::assertCount(2, $http->requests);

        $this->expectException(FileNotFoundException::class);
        $adapter->read('missing');
    }

    #[DataProvider('collectionOperations')]
    public function testCollectionsCannotBeDeletedCopiedMovedOrOverwritten(string $operation): void
    {
        $http    = new RecordingHttpClient(static fn($request) => self::properties($request->getUri()->getPath(), true));
        $adapter = $this->adapter($http);

        self::assertFalse($adapter->exists('collection'));

        try {
            $adapter->$operation('collection', 'other');
            self::fail('Collection operation must fail.');
        } catch (StorageException $exception) {
            self::assertSame(['PROPFIND', 'PROPFIND'], array_map(static fn($r) => $r->getMethod(), $http->requests));
        }
    }

    public static function collectionOperations(): iterable
    {
        foreach (['write', 'delete', 'copy', 'move', 'read'] as $method) {
            yield [$method];
        }
    }

    public function testCopyCannotReplaceADestinationCollection(): void
    {
        $http = new RecordingHttpClient(static fn($request, $count) => self::properties($request->getUri()->getPath(), $count === 2));

        $this->expectException(StorageException::class);
        $this->adapter($http)->copy('file', 'collection');
    }

    #[DataProvider('badResponses')]
    public function testInvalidOrForbiddenPropertyResponsesFailClosed(int $status, string $body): void
    {
        $http = new RecordingHttpClient(static fn() => new Response($status, [], $body));

        $this->expectException(StorageException::class);
        $this->adapter($http)->exists('file');
    }

    public static function badResponses(): iterable
    {
        yield [403, 'forbidden'];
        yield [500, 'failure'];
        yield [207, '<broken'];
        yield [207, '<!DOCTYPE x [<!ENTITY secret SYSTEM "file:///etc/passwd">]><x>&secret;</x>'];
        yield [207, '<d:multistatus xmlns:d="DAV:"/>'];
        yield [207, (string) self::properties('/different')->getBody()];
        yield [207, (string) self::properties('http://[')->getBody()];
        yield [207, str_repeat('x', 1048577)];
    }

    public function testWriteFailureAndFailedCollectionCreation(): void
    {
        $http = new RecordingHttpClient(static fn($request) => match ($request->getMethod()) {
            'PROPFIND' => new Response(404),
            default    => new Response(507),
        });
        $adapter = $this->adapter($http);

        foreach (['file', 'nested/file'] as $path) {
            try {
                $adapter->write($path, 'contents');
                self::fail('Insufficient storage must fail.');
            } catch (UnableToWriteException $exception) {
                self::assertStringContainsString('507', $exception->getMessage());
            }
        }
    }

    public function testCanonicalCollectionCheckDoesNotFollowUntrustedLocation(): void
    {
        $http = new RecordingHttpClient(static fn($request, $count) => $count === 1
            ? new Response(301, ['Location' => 'https://attacker.test/'])
            : self::properties('/files/collection/', true));

        self::assertFalse($this->adapter($http)->exists('collection'));
        self::assertSame('https://dav.example.test/files/collection/', (string) $http->requests[1]->getUri());
    }

    #[DataProvider('invalidPaths')]
    public function testInvalidPathsMakeNoRequests(string $path): void
    {
        $http = new RecordingHttpClient(static fn() => new Response(200));

        try {
            $this->adapter($http)->write($path, 'contents');
            self::fail('Invalid path accepted.');
        } catch (StorageException $exception) {
            self::assertSame([], $http->requests);
        }
    }

    public static function invalidPaths(): iterable
    {
        return S3AdapterTest::invalidPaths();
    }
}
