<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Closure;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class RecordingHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];
    public array $bodies   = [];

    public function __construct(public Closure $handler)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $this->bodies[]   = $request->getBody()->getContents();

        return ($this->handler)($request, count($this->requests));
    }
}
