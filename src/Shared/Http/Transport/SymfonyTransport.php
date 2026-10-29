<?php

declare(strict_types=1);

namespace Slen\Sdk\Shared\Http\Transport;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpClient\Psr18Client;

/** @internal */
final readonly class SymfonyTransport implements TransportInterface
{
    public function __construct(private Psr18Client $client)
    {
    }

    /** @throws ClientExceptionInterface */
    public function send(RequestInterface $request, ?float $timeout): ResponseInterface
    {
        if ($timeout === null) {
            return $this->client->sendRequest($request);
        }

        return $this->client->withOptions(['timeout' => $timeout, 'max_duration' => $timeout])->sendRequest($request);
    }

    public function appliesTimeout(): bool
    {
        return true;
    }

    public function clientClass(): string
    {
        return $this->client::class;
    }
}
