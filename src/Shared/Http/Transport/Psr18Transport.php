<?php

declare(strict_types=1);

namespace Slen\Sdk\Shared\Http\Transport;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** @internal */
final readonly class Psr18Transport implements TransportInterface
{
    public function __construct(private ClientInterface $client)
    {
    }

    /** @throws ClientExceptionInterface */
    public function send(RequestInterface $request, ?float $timeout): ResponseInterface
    {
        return $this->client->sendRequest($request);
    }

    public function appliesTimeout(): bool
    {
        return false;
    }

    public function clientClass(): string
    {
        return $this->client::class;
    }
}
