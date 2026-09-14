<?php

declare(strict_types=1);

namespace Naf\Storage;

use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

/** Private flat storage: callers receive random opaque keys, never client-controlled paths. */
final class LocalStorage
{
    public function __construct(private string $root)
    {
        if (!str_starts_with($root, DIRECTORY_SEPARATOR)) {
            throw new \InvalidArgumentException('Storage root must be absolute.');
        }
        foreach ([$root,$root.'/staging',$root.'/ready'] as $dir) {
            if (is_link($dir)) {
                throw new RuntimeException('Storage directories must not be symlinks.');
            }
            if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new RuntimeException('Cannot create private storage.');
            }
        }
        $this->root = realpath($root) ?: throw new RuntimeException('Cannot resolve storage root.');
    }
    public function stage(UploadedFileInterface $upload, int $maxBytes = 10485760): array
    {
        if ($upload->getError() !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException('Der Upload ist fehlgeschlagen oder zu groß.');
        }
        if ($maxBytes < 1) {
            throw new \InvalidArgumentException('Invalid upload limit.');
        }
        $name = preg_replace('/[\x00-\x1f\x7f]/', '', str_replace('\\', '/', $upload->getClientFilename() ?? 'file'));
        $name = mb_substr(basename($name), 0, 180);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $types = ['txt' => ['text/plain'],'md' => ['text/plain'],'csv' => ['text/plain','text/csv'],'pdf' => ['application/pdf'],'png' => ['image/png'],'jpg' => ['image/jpeg'],'jpeg' => ['image/jpeg'],'webp' => ['image/webp'],'zip' => ['application/zip']];
        if (!isset($types[$extension])) {
            throw new \InvalidArgumentException('Erlaubt sind TXT, MD, CSV, PDF, PNG, JPG, WebP und ZIP.');
        }
        $key = bin2hex(random_bytes(32));
        $path = $this->path($key, 'staging');
        $out = fopen($path, 'x+b');
        if (!$out) {
            throw new RuntimeException('Cannot stage upload.');
        }
        chmod($path, 0600);
        $size = 0;
        $hash = hash_init('sha256');
        try {
            $stream = $upload->getStream();
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            while (!$stream->eof()) {
                $chunk = $stream->read(8192);
                if ($chunk === '') {
                    break;
                }$size += strlen($chunk);
                if ($size > $maxBytes) {
                    throw new \InvalidArgumentException('Die Datei überschreitet das Uploadlimit.');
                }
                hash_update($hash, $chunk);
                if (fwrite($out, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('Incomplete upload write.');
                }
            }
            if ($size === 0) {
                throw new \InvalidArgumentException('Leere Dateien sind nicht erlaubt.');
            }
            fflush($out);
            fclose($out);
            $out = null;
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
            if (!in_array($mime, $types[$extension], true)) {
                throw new \InvalidArgumentException('Dateiinhalt und Dateityp passen nicht zusammen.');
            }
            return ['key' => $key,'name' => $name,'mime' => $mime,'size' => $size,'sha256' => hash_final($hash)];
        } catch (\Throwable $e) {
            if (is_resource($out)) {
                fclose($out);
            }if (is_file($path)) {
                unlink($path);
            }throw $e;
        }
    }
    public function promote(string $key): void
    {
        $ready = $this->path($key, 'ready');
        if (is_file($ready) && !is_link($ready)) {
            return;
        }
        $staged = $this->path($key, 'staging');
        if (is_link($staged) || !is_file($staged) || !@rename($staged, $ready)) {
            throw new RuntimeException('Staged upload is unavailable.');
        }
    }
    /** @return resource */
    public function open(string $key)
    {
        $path = $this->path($key, 'ready');
        if (is_link($path) || !is_file($path)) {
            throw new RuntimeException('Private file is unavailable.');
        }
        return fopen($path, 'rb') ?: throw new RuntimeException('Cannot read private file.');
    }
    public function delete(string $key): void
    {
        foreach (['staging','ready'] as $state) {
            $path = $this->path($key, $state);
            if (is_link($path)) {
                throw new RuntimeException('Unexpected storage symlink.');
            }if (is_file($path) && !unlink($path)) {
                throw new RuntimeException('Cannot delete private file.');
            }
        }
    }
    /** Old unreferenced files only. The host decides which keys are still live. */
    public function cleanup(callable $isReferenced, int $olderThan): int
    {
        $count = 0;
        foreach (['staging','ready'] as $state) {
            foreach (glob($this->root.'/'.$state.'/*') ?: [] as $path) {
                $key = basename($path);
                if (!preg_match('/^[a-f0-9]{64}$/D', $key) || is_link($path) || !is_file($path) || filemtime($path) >= $olderThan || $isReferenced($key)) {
                    continue;
                }
                if (!unlink($path)) {
                    throw new RuntimeException('Cannot clean orphan upload.');
                }$count++;
            }
        }return $count;
    }
    private function path(string $key, string $state): string
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $key) !== 1) {
            throw new \InvalidArgumentException('Invalid storage key.');
        }return $this->root.'/'.$state.'/'.$key;
    }
}
