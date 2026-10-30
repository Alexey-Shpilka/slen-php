<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Operations\Data;

use DateTimeImmutable;

/** Whether the answer of a completed run came from the response cache. */
final readonly class ExecutionCache
{
    /** @param DateTimeImmutable|null $cachedAt when the served answer was stored; null unless $hit */
    public function __construct(
        public bool $hit,
        public ?DateTimeImmutable $cachedAt,
    ) {
    }
}
