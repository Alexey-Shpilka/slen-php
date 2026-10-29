<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit\Support;

use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client that answers from a queue and keeps every request it was given.
 */
final class QueuedHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<ResponseInterface|ClientExceptionInterface> */
    private array $queue = [];

    private readonly Psr17Factory $psr17Factory;

    public function __construct()
    {
        $this->psr17Factory = new Psr17Factory();
    }

    /**
     * @param array<mixed>          $body
     * @param array<string, string> $headers
     */
    public function pushJson(int $status, array $body, array $headers = []): self
    {
        return $this->pushRaw($status, json_encode($body, JSON_THROW_ON_ERROR), ['Content-Type' => 'application/json', ...$headers]);
    }

    /**
     * @param array<string, string> $headers
     */
    public function pushRaw(int $status, string $body, array $headers = []): self
    {
        $response = $this->psr17Factory->createResponse($status)->withBody($this->psr17Factory->createStream($body));

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        $this->queue[] = $response;

        return $this;
    }

    public function pushResponse(ResponseInterface $response): self
    {
        $this->queue[] = $response;

        return $this;
    }

    public function pushException(ClientExceptionInterface $clientException): self
    {
        $this->queue[] = $clientException;

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $next = array_shift($this->queue) ?? throw new LogicException('No answer is queued for ' . $request->getMethod() . ' ' . $request->getUri());

        if ($next instanceof ClientExceptionInterface) {
            throw $next;
        }

        return $next;
    }

    public function lastRequest(): RequestInterface
    {
        return $this->requests[array_key_last($this->requests) ?? throw new LogicException('Nothing was sent.')];
    }
}
