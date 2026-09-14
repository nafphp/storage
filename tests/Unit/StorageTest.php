<?php

declare(strict_types=1);

namespace Tests\Unit;

use Naf\Storage\Adapters\LocalAdapter;
use Naf\Storage\Exceptions\StorageException;
use Naf\Storage\Exceptions\UnableToWriteException;
use Naf\Storage\Storage;
use Naf\Storage\StorageAdapterInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\{FailingStream, PublicAdapter, RecordingAdapter};
use Tests\NafTestCase;

final class StorageTest extends NafTestCase
{
    public function testFacadeWorksWithAnIndependentAdapter(): void
    {
        $storage = new Storage(new RecordingAdapter('documents', (object) ['constructed' => 0]));

        $storage->put('foo.txt', 'Hello World');

        $this->assertSame('Hello World', $storage->get('foo.txt'));

        $storage->copy('foo.txt', 'copy.txt');
        $storage->move('copy.txt', 'moved.txt');

        $this->assertFalse($storage->exists('copy.txt'));
        $this->assertTrue($storage->exists('moved.txt'));

        $input = $storage->readStream('foo.txt');

        try {
            $storage->writeStream('stream.txt', $input);

            $this->assertSame('Hello World', $storage->get('stream.txt'));
            $this->assertTrue(is_resource($input));
        } finally {
            fclose($input);
        }

        $storage->delete('foo.txt');

        $this->assertFalse($storage->exists('foo.txt'));
    }

    #[DataProvider('publicUrls')]
    public function testPublicUrlsEncodeEachPathSegment(string $prefix, string $path, string $expected): void
    {
        $storage = new Storage(new LocalAdapter($this->directory . '/root'), $prefix);

        $this->assertSame($expected, $storage->url($path));
        $this->assertDirectoryDoesNotExist($this->directory . '/root');
    }

    public static function publicUrls(): array
    {
        return [
            ['/storage', 'avatars/123.jpg', '/storage/avatars/123.jpg'],
            ['/storage/', 'a b/ä #?%.png', '/storage/a%20b/%C3%A4%20%23%3F%25.png'],
            ['https://cdn.example/assets/', 'documents/file.pdf', 'https://cdn.example/assets/documents/file.pdf'],
            ['/', 'a.txt', '/a.txt'],
            ['/storage', '%2e%2e/file', '/storage/%252e%252e/file'],
        ];
    }

    public function testPrivateDiskCannotGenerateUrls(): void
    {
        $storage = new Storage(new LocalAdapter($this->directory));

        $this->expectException(StorageException::class);

        $storage->url('private.pdf');
    }

    public function testAdapterMayProvidePublicUrlsAndConfiguredPrefixTakesPrecedence(): void
    {
        $adapter   = new PublicAdapter('bucket', (object) ['constructed' => 0]);
        $storage   = new Storage($adapter);
        $customUrl = new Storage($adapter, '/custom');

        $this->assertSame('https://files.example/bucket/image.png', $storage->url('image.png'));
        $this->assertSame('/custom/image.png', $customUrl->url('image.png'));
    }

    #[DataProvider('invalidUrls')]
    public function testInvalidUrlConfiguration(string $url): void
    {
        $this->expectException(StorageException::class);

        new Storage($this->createStub(StorageAdapterInterface::class), $url);
    }

    public static function invalidUrls(): array
    {
        return array_map(static fn ($url) => [$url], [
            '', 'relative/path', 'javascript:alert(1)', 'file:///tmp', '//host/path',
            'https://user:pass@host', 'https://host/path?x=y', '/path#fragment',
            "https://host/\nheader", 'https://host/space here', '/\\evil',
        ]);
    }

    #[DataProvider('unsafeOperations')]
    public function testFacadeRejectsPathsBeforeInvokingThirdPartyAdapters(string $method, array $arguments): void
    {
        $adapter = $this->createMock(StorageAdapterInterface::class);

        foreach (['write', 'writeStream', 'read', 'readStream', 'exists', 'delete', 'move', 'copy'] as $operation) {
            $adapter->expects($this->never())->method($operation);
        }

        $storage = new Storage($adapter, '/files');

        $this->expectException(StorageException::class);

        $storage->$method(...$arguments);
    }

    public static function unsafeOperations(): array
    {
        return [
            ['put', ['../escape', 'contents']], ['get', ['/etc/passwd']],
            ['exists', ['C:/private']], ['delete', ['php://memory']],
            ['readStream', ['../escape']], ['writeStream', ['../escape', null]],
            ['url', ['../escape']], ['copy', ['source', '../escape']],
            ['move', ['../escape', 'destination']], ['move', ['source', '../escape']],
        ];
    }

    public function testRejectsInvalidClosedAndWriteOnlyStreams(): void
    {
        $closed = fopen('php://temp', 'w+b');

        fclose($closed);

        $writeOnly = fopen($this->directory . '/write-only', 'wb');
        $storages  = [
            new LocalAdapter($this->directory . '/root'),
            new Storage(new LocalAdapter($this->directory . '/root')),
        ];

        try {
            foreach ([null, 'string', new \stdClass(), $closed, $writeOnly] as $stream) {
                foreach ($storages as $storage) {
                    try {
                        $storage->writeStream('file', $stream);
                        $this->fail('Invalid stream accepted');
                    } catch (UnableToWriteException) {
                        $this->addToAssertionCount(1);
                    }
                }
            }
        } finally {
            fclose($writeOnly);
        }

        $this->assertDirectoryDoesNotExist($this->directory . '/root');
    }

    #[DataProvider('streamFailures')]
    public function testFailedStreamsLeaveExistingFilesIntactAndCleanTemporaryFiles(string $failure): void
    {
        stream_wrapper_register('nafbroken', FailingStream::class);

        $stream = fopen('nafbroken://' . $failure, 'rb');

        try {
            $storage = new Storage(new LocalAdapter($this->directory));

            $storage->put('file', 'original');

            try {
                $storage->writeStream('file', $stream);
                $this->fail('Broken stream succeeded');
            } catch (UnableToWriteException) {
                $this->assertSame('original', $storage->get('file'));
                $this->assertTrue(is_resource($stream));
                $this->assertSame(['file'], array_values(array_diff(scandir($this->directory), ['.', '..'])));
            }
        } finally {
            fclose($stream);
            stream_wrapper_unregister('nafbroken');
        }
    }

    public static function streamFailures(): array
    {
        return [['error'], ['stall']];
    }
}
