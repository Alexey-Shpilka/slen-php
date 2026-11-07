<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Executions\Data;

/** What the cost of a provider attempt is made of, as decimal strings of money; $total is their sum. */
final readonly class AttemptCostBreakdown
{
    /** @param array<string, string> $tools each paid server tool by its name */
    public function __construct(
        public string $input,
        public string $cachedInput,
        public string $cacheWrite,
        public string $output,
        public array $tools,
        public string $total,
    ) {
    }
}
