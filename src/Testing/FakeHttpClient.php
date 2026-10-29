<?php

declare(strict_types=1);

namespace Slen\Sdk\Testing;

use Http\Discovery\Psr17FactoryDiscovery;
use LogicException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/** A strict PSR-18 client for tests: answers from a queue in order and keeps every request it was sent. */
final class FakeHttpClient implements ClientInterface
{
    private const int JSON = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    /** @var list<RequestInterface> */
    private array $requests = [];

    /** @var list<ResponseInterface|ClientExceptionInterface> */
    private array $queue = [];

    private readonly ResponseFactoryInterface $responseFactory;

    private readonly StreamFactoryInterface $streamFactory;

    /**
     * @param ResponseFactoryInterface|null $responseFactory discovered when null
     * @param StreamFactoryInterface|null   $streamFactory   discovered when null
     */
    public function __construct(?ResponseFactoryInterface $responseFactory = null, ?StreamFactoryInterface $streamFactory = null)
    {
        $this->responseFactory = $responseFactory ?? Psr17FactoryDiscovery::findResponseFactory();
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
    }

    /**
     * Queues an answer with $body as its JSON.
     *
     * @param array<mixed>          $body
     * @param array<string, string> $headers
     */
    public function pushJson(int $status, array $body, array $headers = []): self
    {
        $response = $this->responseFactory->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream(json_encode($body, self::JSON)));

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        $this->queue[] = $response;

        return $this;
    }

    /** Queues a failure of the transport: a NetworkExceptionInterface is a request that got no answer. */
    public function pushException(ClientExceptionInterface $clientException): self
    {
        $this->queue[] = $clientException;

        return $this;
    }

    /**
     * @throws ClientExceptionInterface the one queued for this request
     * @throws LogicException           when nothing is queued for it
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $next = array_shift($this->queue);

        if ($next === null) {
            throw new LogicException(\sprintf(
                'FakeHttpClient has no answer queued for request #%d (%s %s): queue one with pushJson() or pushException().',
                \count($this->requests),
                $request->getMethod(),
                $request->getUri(),
            ));
        }

        if ($next instanceof ClientExceptionInterface) {
            throw $next;
        }

        return $next;
    }

    /**
     * Every request sent, in order.
     *
     * @return list<RequestInterface>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    /** @throws LogicException when nothing was sent */
    public function lastRequest(): RequestInterface
    {
        return $this->requests[array_key_last($this->requests) ?? throw new LogicException('FakeHttpClient was sent no request.')];
    }
}
