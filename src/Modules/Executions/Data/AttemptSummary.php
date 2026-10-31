<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Executions\Data;

/** One attempt of a provider call a node made. */
final readonly class AttemptSummary
{
    /** @param string|null $cost      a decimal string of money */
    public function __construct(
        public string $attemptId,
        public int $attemptNumber,
        public string $connectionId,
        public ?string $poolId,
        public string $status,
        public ?string $errorCode,
        public ?int $latencyMs,
        public ?string $cost,
        public ?string $providerRequestId,
        public ?string $modelCode,
    ) {
    }
}
