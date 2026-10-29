<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit\Support;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client that keeps nothing: every request gets the same answer, with the request's idempotency key
 * as its X-Request-Id, so a journal record can be traced back to the request that wrote it.
 */
final readonly class ConstantHttpClient implements ClientInterface
{
    private Psr17Factory $psr17Factory;

    private string $body;

    /**
     * @param array<mixed> $answer
     */
    public function __construct(private int $status, array $answer)
    {
        $this->psr17Factory = new Psr17Factory();
        $this->body = json_encode($answer, JSON_THROW_ON_ERROR);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->psr17Factory->createResponse($this->status)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('X-Request-Id', $request->getHeaderLine('Idempotency-Key'))
            ->withBody($this->psr17Factory->createStream($this->body));
    }
}
