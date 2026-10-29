<?php

declare(strict_types=1);

namespace Slen\Sdk\Shared\Http\Transport;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** @internal */
final readonly class GuzzleTransport implements TransportInterface
{
    public function __construct(private ClientInterface $client)
    {
    }

    /** @throws GuzzleException */
    public function send(RequestInterface $request, ?float $timeout): ResponseInterface
    {
        // What PSR-18 sendRequest() sets: a status is an answer, a redirect is not followed.
        $options = ['http_errors' => false, 'allow_redirects' => false];

        if ($timeout !== null) {
            $options['timeout'] = $timeout;
        }

        return $this->client->send($request, $options);
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
