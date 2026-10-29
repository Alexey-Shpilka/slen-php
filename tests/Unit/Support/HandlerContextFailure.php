<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit\Support;

use Nyholm\Psr7\Request;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * A request that got no answer, as Guzzle 7 reports it: the cURL errno in the handler context.
 */
final class HandlerContextFailure extends RuntimeException implements NetworkExceptionInterface
{
    public function __construct(string $message, private readonly int $errno)
    {
        parent::__construct($message);
    }

    public function getRequest(): RequestInterface
    {
        return new Request('GET', 'https://api.slen.ai/api/health');
    }

    /** @return array{errno: int} */
    public function getHandlerContext(): array
    {
        return ['errno' => $this->errno];
    }
}
