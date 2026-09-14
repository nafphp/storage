<?php

declare(strict_types=1);

namespace Naf\Storage\Support;

use Naf\Client\Core\Client;
use Naf\Client\Transports\StreamingTransportInterface;
use Naf\Storage\Exceptions\StorageException;
use Psr\Http\Client\ClientInterface;
use function Naf\Client\client;

/** @internal Reuse NAF's HTTP service; no storage-specific transport or retry loop. */
final class Http
{
    public static function client(?ClientInterface $http): ClientInterface
    {
        if ($http === null) {
            if (!class_exists(Client::class) || !interface_exists(StreamingTransportInterface::class)) {
                throw new StorageException('Remote storage requires naf/client ^0.2.2 or an injected streaming PSR-18 client.');
            }

            $http = client();
        }

        if ($http instanceof Client) {
            if (!interface_exists(StreamingTransportInterface::class)) {
                throw new StorageException('Remote storage requires naf/client ^0.2.2.');
            }

            // Signed requests and credentials must not follow redirects. The SDK
            // owns S3 retries; WebDAV mutations are not automatically replayed.
            return $http->withOptions([
                'streaming'      => true,
                'retries'        => 0,
                'max_redirects'  => 0,
                'decode_content' => false,
            ]);
        }

        return $http;
    }

    public static function endpoint(string $endpoint): string
    {
        $parts = parse_url($endpoint);

        if (
            $parts === false
            || !in_array($parts['scheme'] ?? null, ['https', 'http'], true)
            || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || preg_match('/[\x00-\x20\x7f\\\\]/', $endpoint)
        ) {
            throw new StorageException('The storage endpoint must be an HTTP(S) URL without credentials, query or fragment.');
        }

        $path = trim(rawurldecode($parts['path'] ?? ''), '/');

        if ($path !== '') {
            Path::validate($path);
        }

        return rtrim($endpoint, '/');
    }

    public static function encode(string $path): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', $path)));
    }
}
