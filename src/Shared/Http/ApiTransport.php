<?php

declare(strict_types=1);

namespace Slen\Sdk\Shared\Http;

use Slen\Sdk\Shared\Http\Client\ApiClientInterface;
use Slen\Sdk\Shared\Http\Mappers\ErrorMapper;

/** @internal */
final readonly class ApiTransport
{
    public function __construct(
        public ApiClientInterface $client,
        public ErrorMapper $errorMapper,
        public ?float $readTimeout,
    ) {
    }
}
