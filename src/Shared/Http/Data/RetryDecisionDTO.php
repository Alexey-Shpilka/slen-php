<?php

declare(strict_types=1);

namespace Slen\Sdk\Shared\Http\Data;

use Slen\Sdk\Shared\Http\Enums\RetryReason;

/** @internal */
final readonly class RetryDecisionDTO
{
    public function __construct(
        public RetryReason $reason,
        public int $delayMs,
    ) {
    }
}
