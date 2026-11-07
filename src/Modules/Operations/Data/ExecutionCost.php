<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Operations\Data;

/** What a run was expected to cost and what it was charged, as decimal strings of money. */
final readonly class ExecutionCost
{
    /**
     * @param string|null $estimated  the sum its model calls were authorized at; null when nothing was estimated
     * @param string|null $actual     the sum they were captured for: null until the capture lands after the run,
     *                                and for good when nothing was captured (a refusal, a cache hit)
     * @param bool        $incomplete a provider billed what Slen could not price: $actual is what Slen knew
     */
    public function __construct(
        public ?string $estimated,
        public ?string $actual,
        public bool $incomplete,
    ) {
    }
}
