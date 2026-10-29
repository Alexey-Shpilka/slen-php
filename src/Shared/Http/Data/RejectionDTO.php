<?php

declare(strict_types=1);

namespace Slen\Sdk\Shared\Http\Data;

/** @internal */
final readonly class RejectionDTO
{
    public function __construct(
        public ?int $status,
        public ?string $errorCode,
        public ?string $requestId,
        public string $errorType,
        public int $attempts,
    ) {
    }
}
