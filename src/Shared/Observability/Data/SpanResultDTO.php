<?php

declare(strict_types=1);

namespace Slen\Sdk\Shared\Observability\Data;

/** @internal */
final readonly class SpanResultDTO
{
    public function __construct(
        public string $status,
        public ?string $executionId,
        public ?string $operationCode,
        public ?string $estimated,
        public ?string $actual,
        public ?string $errorCode,
        public ?bool $cacheHit,
    ) {
    }
}
