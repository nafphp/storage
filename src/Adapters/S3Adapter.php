<?php

declare(strict_types=1);

namespace Naf\Storage\Adapters;

use Aws\Exception\AwsException;
use Aws\Exception\MultipartUploadException;
use Aws\S3\S3Client;
use Naf\Storage\Exceptions\FileNotFoundException;
use Naf\Storage\Exceptions\StorageException;
use Naf\Storage\Exceptions\UnableToWriteException;
use Naf\Storage\StorageAdapterInterface;
use Naf\Storage\Support\Http;
use Naf\Storage\Support\Path;
use Naf\Storage\Support\S3HttpHandler;
use Naf\Storage\Support\Streams;
use Nyholm\Psr7\Stream;
use Psr\Http\Client\ClientInterface;
use Throwable;

final class S3Adapter implements StorageAdapterInterface
{
    private readonly S3Client $s3;

    public function __construct(
        private readonly string $bucket,
        string $region,
        string $accessKey,
        string $secretKey,
        ?string $endpoint = null,
        bool $pathStyle = false,
        private readonly string $prefix = '',
        ?string $sessionToken = null,
        ?ClientInterface $client = null,
    ) {
        if (!class_exists(S3Client::class)) {
            throw new StorageException('S3 storage requires aws/aws-sdk-php ^3.395.2.');
        }

        if (!preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/D', $bucket) || $region === '' || $accessKey === '' || $secretKey === '') {
            throw new StorageException('S3 requires a bucket name, region, accessKey and secretKey.');
        }

        if ($prefix !== '') {
            Path::validate($prefix);
        }

        $options = [
            'version'                      => '2006-03-01',
            'region'                       => $region,
            'use_path_style_endpoint'      => $pathStyle,
            'credentials'                  => ['key' => $accessKey, 'secret' => $secretKey, 'token' => $sessionToken],
            'http_handler'                 => new S3HttpHandler(Http::client($client)),
            'retries'                      => 2,
            'request_checksum_calculation' => 'when_required',
            'response_checksum_validation' => 'when_required',
        ];

        if ($endpoint !== null) {
            $options['endpoint'] = Http::endpoint($endpoint);
        }

        try {
            $this->s3 = new S3Client($options);
        } catch (Throwable $exception) {
            throw new StorageException('Cannot configure S3 storage.', 0, $exception);
        }
    }

    public function write(string $path, string $contents): void
    {
        $body   = Stream::create($contents);
        $stream = $body->detach();

        try {
            $this->writeStream($path, $stream);
        } finally {
            fclose($stream);
        }
    }

    /** @param resource $stream Readable from its current position; left open. */
    public function writeStream(string $path, mixed $stream): void
    {
        $key  = $this->key($path);
        $body = Streams::snapshot($stream);

        try {
            // A private, seekable snapshot supports SDK hashing/retries and multipart
            // without rewinding caller streams or buffering entire files in memory.
            $this->s3->upload($this->bucket, $key, $body, null, ['concurrency' => 1]);
        } catch (Throwable $exception) {
            $this->failure($exception, $path, true);
        } finally {
            fclose($body);
        }
    }

    public function read(string $path): string
    {
        $stream = $this->readStream($path);

        try {
            $contents = stream_get_contents($stream);

            if ($contents === false) {
                throw new StorageException('Cannot read the S3 download stream.');
            }

            return $contents;
        } finally {
            fclose($stream);
        }
    }

    /** @return resource Readable at its beginning; the caller closes it. */
    public function readStream(string $path): mixed
    {
        $key = $this->key($path);

        try {
            $result = $this->s3->getObject(['Bucket' => $this->bucket, 'Key' => $key]);

            return Streams::resource($result['Body']);
        } catch (Throwable $exception) {
            $this->failure($exception, $path);
        }
    }

    public function exists(string $path): bool
    {
        $key = $this->key($path);

        try {
            $this->s3->headObject(['Bucket' => $this->bucket, 'Key' => $key]);

            return true;
        } catch (AwsException $exception) {
            if ($this->missing($exception)) {
                return false;
            }

            $this->failure($exception, $path);
        } catch (Throwable $exception) {
            $this->failure($exception, $path);
        }
    }

    public function delete(string $path): void
    {
        $key = $this->key($path);

        try {
            $this->s3->deleteObject(['Bucket' => $this->bucket, 'Key' => $key]);
        } catch (Throwable $exception) {
            $this->failure($exception, $path);
        }
    }

    public function move(string $source, string $destination): void
    {
        $this->copy($source, $destination);

        if ($source !== $destination) {
            $this->delete($source);
        }
    }

    public function copy(string $source, string $destination): void
    {
        $sourceKey      = $this->key($source);
        $destinationKey = $this->key($destination);

        if ($source === $destination) {
            if (!$this->exists($source)) {
                throw new FileNotFoundException("Storage file '$source' does not exist.");
            }

            return;
        }

        try {
            // The SDK selects CopyObject or multipart server-side copy by object size.
            $this->s3->copy($this->bucket, $sourceKey, $this->bucket, $destinationKey, null, ['concurrency' => 1]);
        } catch (Throwable $exception) {
            $this->failure($exception, $source, true);
        }
    }

    private function key(string $path): string
    {
        Path::validate($path);

        return $this->prefix === '' ? $path : $this->prefix . '/' . $path;
    }

    private function missing(AwsException $exception): bool
    {
        return $exception->getStatusCode() === 404 && $exception->getAwsErrorCode() !== 'NoSuchBucket';
    }

    private function failure(Throwable $exception, string $path, bool $writing = false): never
    {
        if ($exception instanceof MultipartUploadException) {
            $id = $exception->getState()->getId();

            if (!empty($id['UploadId'])) {
                try {
                    $this->s3->abortMultipartUpload($id);
                } catch (Throwable $cleanup) {
                    throw new UnableToWriteException('S3 transfer failed and its multipart upload could not be aborted.', 0, $exception);
                }
            }
        }

        if ($exception instanceof AwsException && $this->missing($exception)) {
            throw new FileNotFoundException("Storage file '$path' does not exist.", 0, $exception);
        }

        $class = $writing ? UnableToWriteException::class : StorageException::class;

        throw new $class("S3 storage operation failed for '$path'.", 0, $exception);
    }
}
