<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit\Support;

use Nyholm\Psr7\Request;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Throwable;

/**
 * A request that got no answer, as a PSR-18 client reports it.
 */
final class NetworkFailure extends RuntimeException implements NetworkExceptionInterface
{
    public function __construct(string $message = 'Connection refused', ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function getRequest(): RequestInterface
    {
        return new Request('GET', 'https://api.slen.ai/api/health');
    }
}
