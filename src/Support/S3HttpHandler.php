<?php

declare(strict_types=1);

namespace Naf\Storage\Support;

use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Throwable;

/** @internal The AWS SDK's handler contract, backed exclusively by the NAF/PSR-18 client. */
final class S3HttpHandler
{
    public function __construct(private readonly ClientInterface $http)
    {
    }

    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        try {
            // SDK retry middleware communicates its backoff through the handler.
            if (($options['delay'] ?? 0) > 0) {
                usleep((int) ($options['delay'] * 1000));
            }

            $response = $this->http->sendRequest($request);
            $status   = $response->getStatusCode();

            // PSR-18 returns HTTP errors; the SDK expects rejected promises so
            // its error parser can distinguish missing keys from denied access.
            if ($status >= 300) {
                return new RejectedPromise([
                    'exception'        => new RuntimeException("S3 returned HTTP $status."),
                    'response'         => $response,
                    'connection_error' => false,
                ]);
            }

            return new FulfilledPromise($response);
        } catch (Throwable $exception) {
            return new RejectedPromise([
                'exception'        => $exception,
                'connection_error' => true,
            ]);
        }
    }
}
