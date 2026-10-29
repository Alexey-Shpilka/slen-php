<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Operations\Data;

use DateTimeImmutable;

/** What a `waiting` run waits on: the node it parked at and why. */
final readonly class WaitingOnDTO
{
    /**
     * @param string                 $type     queue_node, approval, rate_limit_queue or provider_background
     * @param DateTimeImmutable|null $resumeAt when it is picked up again, when that is known
     */
    public function __construct(
        public string $nodeKey,
        public string $type,
        public ?DateTimeImmutable $resumeAt,
    ) {
    }
}
