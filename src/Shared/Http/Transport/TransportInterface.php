<?php

declare(strict_types=1);

namespace Slen\Sdk\Shared\Http\Transport;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** @internal */
interface TransportInterface
{
    /** @throws ClientExceptionInterface */
    public function send(RequestInterface $request, ?float $timeout): ResponseInterface;

    public function appliesTimeout(): bool;

    /** @return class-string */
    public function clientClass(): string;
}
