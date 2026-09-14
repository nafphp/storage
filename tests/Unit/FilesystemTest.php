<?php

declare(strict_types=1);

namespace Tests\Unit;

use Naf\Storage\Adapters\LocalAdapter;
use Naf\Storage\Exceptions\StorageException;
use Naf\Storage\Exceptions\UnableToWriteException;
use Naf\Storage\Filesystem;
use Naf\Storage\StorageAdapterInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\{FailingStream, PublicAdapter, RecordingAdapter};
use Tests\NafTestCase;

final class FilesystemTest extends NafTestCase
{
    public function testFacadeWorksWithAnIndependentAdapter(): void
    {
        $filesystem = new Filesystem(new RecordingAdapter('documents', (object) ['constructed' => 0]));
        $filesystem->put('foo.txt', 'Hello World');
        $this->assertSame('Hello World', $filesystem->get('foo.txt'));
        $filesystem->copy('foo.txt', 'copy.txt');
        $filesystem->move('copy.txt', 'moved.txt');
        $this->assertFalse($filesystem->exists('copy.txt'));
        $this->assertTrue($filesystem->exists('moved.txt'));
        $input = $filesystem->readStream('foo.txt');
        try {
            $filesystem->writeStream('stream.txt', $input);
            $this->assertSame('Hello World', $filesystem->get('stream.txt'));
            $this->assertTrue(is_resource($input));
        } finally {
            fclose($input);
        }
        $filesystem->delete('foo.txt');
        $this->assertFalse($filesystem->exists('foo.txt'));
    }

    #[DataProvider('publicUrls')]
    public function testPublicUrlsEncodeEachPathSegment(string $prefix, string $path, string $expected): void
    {
        $filesystem = new Filesystem(new LocalAdapter($this->directory . '/root'), $prefix);
        $this->assertSame($expected, $filesystem->url($path));
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
        $filesystem = new Filesystem(new LocalAdapter($this->directory));
        $this->expectException(StorageException::class);
        $filesystem->url('private.pdf');
    }

    public function testAdapterMayProvidePublicUrlsAndConfiguredPrefixTakesPrecedence(): void
    {
        $adapter = new PublicAdapter('bucket', (object) ['constructed' => 0]);
        $this->assertSame('https://files.example/bucket/image.png', (new Filesystem($adapter))->url('image.png'));
        $this->assertSame('/custom/image.png', (new Filesystem($adapter, '/custom'))->url('image.png'));
    }

    #[DataProvider('invalidUrls')]
    public function testInvalidUrlConfiguration(string $url): void
    {
        $this->expectException(StorageException::class);
        new Filesystem($this->createStub(StorageAdapterInterface::class), $url);
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
        $filesystem = new Filesystem($adapter, '/files');
        $this->expectException(StorageException::class);
        $filesystem->$method(...$arguments);
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
        try {
            foreach ([null, 'string', new \stdClass(), $closed, $writeOnly] as $stream) {
                foreach ([new LocalAdapter($this->directory . '/root'), new Filesystem(new LocalAdapter($this->directory . '/root'))] as $storage) {
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
            $filesystem = new Filesystem(new LocalAdapter($this->directory));
            $filesystem->put('file', 'original');
            try {
                $filesystem->writeStream('file', $stream);
                $this->fail('Broken stream succeeded');
            } catch (UnableToWriteException) {
                $this->assertSame('original', $filesystem->get('file'));
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
