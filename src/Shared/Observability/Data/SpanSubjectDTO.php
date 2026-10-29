<?php

declare(strict_types=1);

namespace Slen\Sdk\Shared\Observability\Data;

/** @internal */
final readonly class SpanSubjectDTO
{
    public function __construct(
        public string $operation,
        public ?string $operationCode = null,
        public ?string $executionId = null,
    ) {
    }
}
