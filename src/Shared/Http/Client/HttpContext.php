<?php

declare(strict_types=1);

namespace Slen\Sdk\Shared\Http\Client;

use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Slen\Sdk\Shared\Http\Transport\TransportInterface;

/** @internal */
final readonly class HttpContext
{
    public function __construct(
        public TransportInterface $transport,
        public RequestFactoryInterface $requestFactory,
        public StreamFactoryInterface $streamFactory,
    ) {
    }
}
