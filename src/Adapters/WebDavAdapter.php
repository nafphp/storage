<?php

declare(strict_types=1);

namespace Naf\Storage\Adapters;

use DOMDocument;
use DOMXPath;
use Naf\Storage\Exceptions\FileNotFoundException;
use Naf\Storage\Exceptions\StorageException;
use Naf\Storage\Exceptions\UnableToWriteException;
use Naf\Storage\StorageAdapterInterface;
use Naf\Storage\Support\Http;
use Naf\Storage\Support\Path;
use Naf\Storage\Support\Streams;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Stream;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final class WebDavAdapter implements StorageAdapterInterface
{
    private readonly string $endpoint;
    private readonly ClientInterface $http;
    private readonly string $authorization;

    public function __construct(
        string $endpoint,
        string $username,
        string $password,
        ?ClientInterface $client = null,
    ) {
        if (!class_exists(DOMDocument::class)) {
            throw new StorageException('WebDAV storage requires ext-dom.');
        }

        if ($username === '' || str_contains($username, ':') || preg_match('/[\x00-\x1f\x7f]/', $username . $password)) {
            throw new StorageException('WebDAV requires a username without colons and credentials without control characters.');
        }

        $this->endpoint      = Http::endpoint($endpoint);
        $this->http          = Http::client($client);
        $this->authorization = 'Basic ' . base64_encode($username . ':' . $password);
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

    /** @param resource $stream Consumed from its current position; never closed or rewound. */
    public function writeStream(string $path, mixed $stream): void
    {
        $url      = $this->url($path);
        $snapshot = Streams::snapshot($stream);
        $body     = Stream::create($snapshot);

        try {
            $this->assertFile($path, true);
            $this->parents($path);

            $response = $this->send('PUT', $url, ['Content-Type' => 'application/octet-stream'], $body);

            $this->expect($response, [200, 201, 204], $path, true);
        } finally {
            $body->close();
        }
    }

    public function read(string $path): string
    {
        $stream = $this->readStream($path);

        try {
            $contents = stream_get_contents($stream);

            if ($contents === false) {
                throw new StorageException('Cannot read the WebDAV download stream.');
            }

            return $contents;
        } finally {
            fclose($stream);
        }
    }

    /** @return resource Readable at its beginning; the caller closes it. */
    public function readStream(string $path): mixed
    {
        $this->assertFile($path);

        $response = $this->send('GET', $this->url($path));

        $this->expect($response, [200], $path, close: false);

        return Streams::resource($response->getBody());
    }

    public function exists(string $path): bool
    {
        return $this->type($this->url($path)) === 'file';
    }

    public function delete(string $path): void
    {
        if (!$this->assertFile($path, true)) {
            return;
        }

        $response = $this->send('DELETE', $this->url($path));

        $this->expect($response, [200, 204, 404], $path);
    }

    public function move(string $source, string $destination): void
    {
        $this->transfer('MOVE', $source, $destination);
    }

    public function copy(string $source, string $destination): void
    {
        $this->transfer('COPY', $source, $destination);
    }

    private function transfer(string $method, string $source, string $destination): void
    {
        $sourceUrl      = $this->url($source);
        $destinationUrl = $this->url($destination);

        $this->assertFile($source);

        if ($source === $destination) {
            return;
        }

        $this->assertFile($destination, true);
        $this->parents($destination);

        $headers = ['Destination' => $destinationUrl, 'Overwrite' => 'T'];

        if ($method === 'COPY') {
            $headers['Depth'] = '0';
        }

        $response = $this->send($method, $sourceUrl, $headers);

        $this->expect($response, [201, 204], $source, true);
    }

    private function url(string $path): string
    {
        Path::validate($path);

        return $this->endpoint . '/' . Http::encode($path);
    }

    private function parents(string $path): void
    {
        $segments = explode('/', $path);
        $parent   = $this->endpoint;

        array_pop($segments);

        foreach ($segments as $segment) {
            $parent .= '/' . rawurlencode($segment);
            $type    = $this->type($parent . '/');

            if ($type === 'collection') {
                continue;
            }

            if ($type === 'file') {
                throw new UnableToWriteException('A WebDAV parent path is a file.');
            }

            $response = $this->send('MKCOL', $parent . '/');
            $status   = $response->getStatusCode();

            $response->getBody()->close();

            // Another writer may have created the collection after PROPFIND.
            if ($status === 405 && $this->type($parent . '/') === 'collection') {
                continue;
            }

            if ($status !== 201) {
                throw new UnableToWriteException("Cannot create WebDAV collection (HTTP $status).");
            }
        }
    }

    private function assertFile(string $path, bool $allowMissing = false): bool
    {
        $type = $this->type($this->url($path));

        if ($type === 'collection') {
            throw new StorageException("Storage path '$path' is a WebDAV collection, not a file.");
        }

        if ($type === null && !$allowMissing) {
            throw new FileNotFoundException("Storage file '$path' does not exist.");
        }

        return $type === 'file';
    }

    /** PROPFIND distinguishes files from collections, preventing recursive DELETE/COPY/MOVE. */
    private function type(string $url): ?string
    {
        $requestBody = '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:resourcetype/></d:prop></d:propfind>';
        $headers     = ['Depth' => '0', 'Content-Type' => 'application/xml; charset=utf-8'];
        $response    = $this->send('PROPFIND', $url, $headers, $requestBody);
        $status      = $response->getStatusCode();

        // Some servers canonicalize collection URLs. Only append one slash to the
        // exact requested path; never follow an arbitrary redirect with credentials.
        if (in_array($status, [301, 308], true) && !str_ends_with($url, '/')) {
            $response->getBody()->close();

            return $this->type($url . '/');
        }

        if ($status === 404) {
            $response->getBody()->close();

            return null;
        }

        $this->expect($response, [207], 'WebDAV resource', close: false);

        $body = $response->getBody();
        $xml  = '';

        try {
            while (!$body->eof() && strlen($xml) <= 1048576) {
                $chunk = $body->read(65536);

                if ($chunk === '' && !$body->eof()) {
                    throw new StorageException('The WebDAV property response stalled.');
                }

                $xml .= $chunk;
            }
        } catch (Throwable $exception) {
            throw new StorageException('Cannot read WebDAV properties.', 0, $exception);
        } finally {
            $body->close();
        }

        if (strlen($xml) > 1048576 || stripos($xml, '<!DOCTYPE') !== false) {
            throw new StorageException('Invalid or oversized WebDAV property response.');
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $document = new DOMDocument();

            if (!$document->loadXML($xml, LIBXML_NONET)) {
                throw new StorageException('Invalid WebDAV XML response.');
            }

            $xpath = new DOMXPath($document);

            $xpath->registerNamespace('d', 'DAV:');

            $responses = $xpath->query('/d:multistatus/d:response');

            if ($responses->length !== 1) {
                throw new StorageException('WebDAV returned an unexpected number of resources.');
            }

            $href      = $xpath->evaluate('string(d:href)', $responses->item(0));
            $hrefPath  = parse_url($href, PHP_URL_PATH);

            if (!is_string($hrefPath)) {
                throw new StorageException('WebDAV returned an invalid resource URL.');
            }

            $hrefPath  = rawurldecode($hrefPath);
            $urlPath   = rawurldecode(parse_url($url, PHP_URL_PATH) ?? '');

            if (rtrim($hrefPath, '/') !== rtrim($urlPath, '/')) {
                throw new StorageException('WebDAV returned properties for an unexpected resource.');
            }

            foreach ($xpath->query('d:propstat', $responses->item(0)) as $property) {
                $status = $xpath->evaluate('string(d:status)', $property);
                $type   = $xpath->query('d:prop/d:resourcetype', $property);

                if (preg_match('~^HTTP/\d+(?:\.\d+)? 200(?:\s|$)~', $status) && $type->length === 1) {
                    return $xpath->query('d:collection', $type->item(0))->length > 0 ? 'collection' : 'file';
                }
            }

            throw new StorageException('WebDAV did not return a successful resource type.');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function send(string $method, string $url, array $headers = [], mixed $body = null): ResponseInterface
    {
        $headers['Authorization'] = $this->authorization;

        try {
            return $this->http->sendRequest(new Request($method, $url, $headers, $body));
        } catch (Throwable $exception) {
            $writing = in_array($method, ['PUT', 'MKCOL', 'COPY', 'MOVE'], true);
            $class   = $writing ? UnableToWriteException::class : StorageException::class;

            throw new $class("WebDAV $method request failed.", 0, $exception);
        }
    }

    private function expect(ResponseInterface $response, array $statuses, string $path, bool $writing = false, bool $close = true): void
    {
        $status = $response->getStatusCode();

        if ($close || !in_array($status, $statuses, true)) {
            $response->getBody()->close();
        }

        if (in_array($status, $statuses, true)) {
            return;
        }

        if ($status === 404) {
            throw new FileNotFoundException("Storage file '$path' does not exist.");
        }

        $class = $writing ? UnableToWriteException::class : StorageException::class;

        throw new $class("WebDAV operation failed for '$path' (HTTP $status).");
    }
}
