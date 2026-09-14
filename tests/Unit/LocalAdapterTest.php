<?php

declare(strict_types=1);

namespace Tests\Unit;

use Naf\Storage\Adapters\LocalAdapter;
use Naf\Storage\Exceptions\FileNotFoundException;
use Naf\Storage\Exceptions\StorageException;
use Naf\Storage\Exceptions\UnableToWriteException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\NafTestCase;

final class LocalAdapterTest extends NafTestCase
{
    private LocalAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adapter = new LocalAdapter($this->directory . '/root');
    }

    public function testDirectoriesAreLazyAndCreatedRecursivelyWithPrivatePermissions(): void
    {
        $this->assertDirectoryDoesNotExist($this->directory . '/root');
        $this->assertFalse($this->adapter->exists('missing.txt'));
        $this->assertDirectoryDoesNotExist($this->directory . '/root');
        $this->adapter->write('attachments/tickets/42/error.png', 'image bytes');
        $this->assertSame('image bytes', $this->adapter->read('attachments/tickets/42/error.png'));
        $this->assertSame(0700, fileperms($this->directory . '/root') & 0777);
        $this->assertSame(0700, fileperms($this->directory . '/root/attachments/tickets/42') & 0777);
        $this->assertSame(0600, fileperms($this->directory . '/root/attachments/tickets/42/error.png') & 0777);
    }

    public function testWritesOverwriteAndAcceptEmptyAndBinaryContents(): void
    {
        $this->adapter->write('file', str_repeat('original', 2000));
        $this->adapter->write('file', "\0\xffshort");
        $this->assertSame("\0\xffshort", $this->adapter->read('file'));
        $this->adapter->write('file', '');
        $this->assertSame('', $this->adapter->read('file'));
        $this->assertTrue($this->adapter->exists('file'));
        $this->assertSame(['file'], array_values(array_diff(scandir($this->directory . '/root'), ['.', '..'])));
    }

    public function testStreamsUseCurrentPositionAndRemainOwnedByCaller(): void
    {
        $input = fopen('php://temp', 'w+b');
        try {
            fwrite($input, 'skip:streamed contents');
            fseek($input, 5);
            $this->adapter->writeStream('nested/file', $input);
            $this->assertTrue(is_resource($input));
            $this->assertTrue(feof($input));
            $output = $this->adapter->readStream('nested/file');
            try {
                $this->assertSame(0, ftell($output));
                $this->assertSame('streamed contents', stream_get_contents($output));
            } finally {
                fclose($output);
            }
            $this->adapter->writeStream('empty', $input);
            $this->assertSame('', $this->adapter->read('empty'));
        } finally {
            fclose($input);
        }
    }

    public function testNonSeekableStreams(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        [$input, $output] = $pair;
        try {
            fwrite($output, 'non-seekable');
            stream_socket_shutdown($output, STREAM_SHUT_WR);
            $this->assertFalse(stream_get_meta_data($input)['seekable']);
            $this->adapter->writeStream('pipe', $input);
            $this->assertSame('non-seekable', $this->adapter->read('pipe'));
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    public function testLargeStreamsAndCopyDoNotBufferTheWholeFile(): void
    {
        $input = fopen($this->directory . '/large', 'w+b');
        try {
            $chunk = str_repeat('abcdefgh', 8192);
            for ($i = 0; $i < 512; $i++) {
                fwrite($input, $chunk);
            }
            rewind($input);
            $before = memory_get_usage(true);
            memory_reset_peak_usage();
            $this->adapter->writeStream('large', $input);
            $this->adapter->copy('large', 'copy');
            $this->assertLessThan(8 * 1024 * 1024, memory_get_peak_usage(true) - $before);
            $this->assertSame(32 * 1024 * 1024, filesize($this->directory . '/root/copy'));
            $this->assertSame(hash_file('sha256', $this->directory . '/large'), hash_file('sha256', $this->directory . '/root/copy'));
        } finally {
            fclose($input);
        }
    }

    public function testCopyMoveOverwriteCreateParentsAndHandleSamePath(): void
    {
        $this->adapter->write('source', 'new');
        $this->adapter->write('copy', 'old longer contents');
        $this->adapter->copy('source', 'copy');
        $this->assertSame('new', $this->adapter->read('copy'));
        $this->assertTrue($this->adapter->exists('source'));
        $this->adapter->copy('copy', 'copy');
        $this->adapter->move('copy', 'copy');
        $this->adapter->move('source', 'nested/deep/moved');
        $this->assertFalse($this->adapter->exists('source'));
        $this->assertSame('new', $this->adapter->read('nested/deep/moved'));
        $this->adapter->write('replacement', 'replacement');
        $this->adapter->move('replacement', 'copy');
        $this->assertSame('replacement', $this->adapter->read('copy'));
        $this->adapter->copy('copy', 'copied/into/new/parents');
        $this->assertSame('replacement', $this->adapter->read('copied/into/new/parents'));
    }

    public function testDeleteIsIdempotentAndDoesNotDeleteDirectories(): void
    {
        $this->adapter->delete('missing');
        $this->adapter->write('nested/file', 'contents');
        $this->adapter->delete('nested/file');
        $this->adapter->delete('nested/file');
        $this->assertFalse($this->adapter->exists('nested/file'));
        $this->assertFalse($this->adapter->exists('nested'));
        $this->expectException(StorageException::class);
        $this->adapter->delete('nested');
    }

    #[DataProvider('missingOperations')]
    public function testMissingSourceThrowsSpecificException(string $operation): void
    {
        $this->expectException(FileNotFoundException::class);
        $this->adapter->$operation('missing', 'destination');
    }

    public static function missingOperations(): array
    {
        return [['read'], ['readStream'], ['move'], ['copy']];
    }

    #[DataProvider('invalidPaths')]
    public function testRejectsInvalidPathsOnEveryOperation(string $path): void
    {
        foreach (['write', 'read', 'readStream', 'exists', 'delete', 'move', 'copy'] as $operation) {
            try {
                $this->adapter->$operation($path, 'destination');
                $this->fail("$operation accepted an unsafe path");
            } catch (StorageException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach (['move', 'copy'] as $operation) {
            try {
                $this->adapter->$operation('source', $path);
                $this->fail("$operation accepted an unsafe destination");
            } catch (FileNotFoundException) {
                $this->fail('Destination validation must happen before accessing the source');
            } catch (StorageException) {
                $this->addToAssertionCount(1);
            }
        }
        $input = fopen('php://temp', 'r+');
        try {
            $this->expectException(StorageException::class);
            $this->adapter->writeStream($path, $input);
        } finally {
            fclose($input);
        }
    }

    public static function invalidPaths(): array
    {
        return array_map(static fn ($path) => [$path], [
            '', '../foo', '/foo', '//server/share', 'foo/../../etc/passwd', './foo', 'foo/./bar',
            'C:/Windows/win.ini', 'C:\\Windows\\win.ini', 'C:relative', '\\rooted', '\\\\server\\share',
            'php://filter/resource=file', 'https://example.com/file', 'data:text/plain,hello',
            "foo\0bar", "foo\nbar", 'foo/../bar', 'foo//bar', 'foo/', 'folder\\file',
            'file:stream', 'NUL', 'con.txt', 'directory/LPT1.log', 'file.', 'file ',
        ]);
    }

    public function testAllowsNestedUnicodeNamesAndEncodableCharacters(): void
    {
        $path = 'attachments/tickets/42/über uns #100%.png';
        $this->adapter->write($path, 'ok');
        $this->assertSame('ok', $this->adapter->read($path));
    }

    #[DataProvider('symlinkLocations')]
    public function testRejectsSymlinksIncludingDanglingLinks(string $location, bool $dangling): void
    {
        mkdir($this->directory . '/outside');
        file_put_contents($this->directory . '/outside/secret', 'outside');
        mkdir($this->directory . '/root');
        $target = $this->directory . ($dangling ? '/absent' : '/outside');
        if ($location === 'root') {
            rmdir($this->directory . '/root');
            symlink($target, $this->directory . '/root');
            $path = 'secret';
        } elseif ($location === 'parent') {
            symlink($target, $this->directory . '/root/link');
            $path = 'link/secret';
        } else {
            symlink($target . '/secret', $this->directory . '/root/link');
            $path = 'link';
        }
        foreach (['write', 'read', 'readStream', 'exists', 'delete', 'move', 'copy'] as $operation) {
            try {
                $this->adapter->$operation($path, 'destination');
                $this->fail("$operation followed a symlink");
            } catch (StorageException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('outside', file_get_contents($this->directory . '/outside/secret'));
    }

    public static function symlinkLocations(): array
    {
        return [['root', false], ['parent', false], ['file', false], ['root', true], ['parent', true], ['file', true]];
    }

    #[DataProvider('targetOperations')]
    public function testCopyMoveCannotWriteThroughSymlinkDestination(string $operation): void
    {
        $this->adapter->write('source', 'inside');
        file_put_contents($this->directory . '/outside', 'outside');
        symlink($this->directory . '/outside', $this->directory . '/root/link');
        try {
            $this->adapter->$operation('source', 'link');
            $this->fail('Followed destination symlink');
        } catch (StorageException) {
            $this->assertSame('outside', file_get_contents($this->directory . '/outside'));
            $this->assertSame('inside', $this->adapter->read('source'));
        }
    }

    public static function targetOperations(): array
    {
        return [['move'], ['copy']];
    }

    public function testDirectoryAndFileCollisionsProduceExceptions(): void
    {
        $this->adapter->write('blocker', 'file');
        $this->expectException(UnableToWriteException::class);
        $this->adapter->write('blocker/nested', 'never');
    }

    public function testRootOccupiedByFile(): void
    {
        file_put_contents($this->directory . '/root', 'occupied');
        $this->expectException(UnableToWriteException::class);
        $this->adapter->write('file', 'never');
    }

    public function testFailedNativeWritePreservesErrorHandlerAndOriginalContents(): void
    {
        $this->adapter->write('file', 'original');
        chmod($this->directory . '/root', 0500);
        if (is_writable($this->directory . '/root')) {
            $this->markTestSkipped('Permission checks require an unprivileged user.');
        }
        $handler = static function (): never {
            throw new \LogicException('Warning escaped storage');
        };
        set_error_handler($handler);
        try {
            try {
                $this->adapter->write('file', 'replacement');
                $this->fail('Write to read-only directory succeeded');
            } catch (UnableToWriteException $exception) {
                $this->assertInstanceOf(\ErrorException::class, $exception->getPrevious());
            }
            $current = set_error_handler($handler);
            restore_error_handler();
            $this->assertSame($handler, $current);
            $this->assertSame('original', $this->adapter->read('file'));
        } finally {
            restore_error_handler();
            chmod($this->directory . '/root', 0700);
        }
    }

    public function testPermissionModesAreConfigurableAndExistingDirectoriesArePreserved(): void
    {
        mkdir($this->directory . '/root', 0750);
        chmod($this->directory . '/root', 0750);
        $adapter = new LocalAdapter($this->directory . '/root', fileMode: 0644, directoryMode: 0755);
        $adapter->write('sub/file', 'public');
        $this->assertSame(0750, fileperms($this->directory . '/root') & 0777);
        $this->assertSame(0755, fileperms($this->directory . '/root/sub') & 0777);
        $this->assertSame(0644, fileperms($this->directory . '/root/sub/file') & 0777);
    }

    #[DataProvider('invalidRoots')]
    public function testInvalidRootConfiguration(string $root): void
    {
        $this->expectException(StorageException::class);
        new LocalAdapter($root);
    }

    public static function invalidRoots(): array
    {
        return [[''], ['relative/root'], ['php://temp'], ['https://example.com'], ["/root\0bad"], ['/']];
    }

    public function testInvalidPermissionModes(): void
    {
        $this->expectException(StorageException::class);
        new LocalAdapter($this->directory, fileMode: 07777);
    }

    #[DataProvider('readOnlyOperations')]
    public function testFilesystemPermissionFailuresAreReported(string $operation): void
    {
        $this->adapter->write('source', 'original');
        $this->adapter->write('destination', 'destination');
        $restricted = $operation === 'read' || $operation === 'readStream'
            ? $this->directory . '/root/source'
            : $this->directory . '/root';
        chmod($restricted, $operation === 'read' || $operation === 'readStream' ? 0000 : 0500);
        clearstatcache();
        if (is_writable($this->directory . '/root') && is_readable($this->directory . '/root/source')) {
            $this->markTestSkipped('Permission checks require an unprivileged user.');
        }
        try {
            $this->expectException(StorageException::class);
            $this->adapter->$operation('source', 'destination');
        } finally {
            chmod($this->directory . '/root', 0700);
            chmod($this->directory . '/root/source', 0600);
        }
    }

    public static function readOnlyOperations(): array
    {
        return [['read'], ['readStream'], ['delete'], ['move'], ['copy']];
    }

    public function testDirectoriesCannotBeOverwrittenOrReadAsFiles(): void
    {
        $this->adapter->write('directory/child', 'child');
        foreach (['write', 'read', 'readStream'] as $operation) {
            try {
                $this->adapter->$operation('directory', 'replacement');
                $this->fail('Directory accepted as file');
            } catch (StorageException) {
                $this->assertSame('child', $this->adapter->read('directory/child'));
            }
        }
    }

    public function testSpecialFilesystemNodesAreRejectedWithoutOpeningThem(): void
    {
        if (!function_exists('posix_mkfifo')) {
            $this->markTestSkipped('POSIX FIFO support is unavailable.');
        }
        $this->adapter->write('regular', 'contents');
        posix_mkfifo($this->directory . '/root/fifo', 0600);
        foreach (['read', 'readStream', 'write', 'exists', 'delete'] as $operation) {
            try {
                $this->adapter->$operation('fifo', 'contents');
                $this->fail('Special file accepted');
            } catch (StorageException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testInaccessibleDirectoryIsNotReportedAsMissingFile(): void
    {
        $this->adapter->write('private/file', 'contents');
        chmod($this->directory . '/root/private', 0000);
        clearstatcache();
        if (is_readable($this->directory . '/root/private')) {
            $this->markTestSkipped('Permission checks require an unprivileged user.');
        }
        try {
            $this->expectException(StorageException::class);
            $this->adapter->exists('private/file');
        } finally {
            chmod($this->directory . '/root/private', 0700);
        }
    }
}
