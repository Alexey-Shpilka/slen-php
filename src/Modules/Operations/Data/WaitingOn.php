<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Operations\Data;

use DateTimeImmutable;

/** What a `waiting` run waits on: the node it parked at and why. */
final readonly class WaitingOn
{
    /**
     * @param string                 $type     queue_node, approval, rate_limit_queue, provider_background or queue_slot — a slot of a Queue node's
     *                                         limit of calls at once; the run goes on by itself when one is freed
     * @param DateTimeImmutable|null $resumeAt when it is picked up again, when that is known; for queue_slot the deadline, when a run still
     *                                         without a slot leaves by the node's `busy` port
     */
    public function __construct(
        public string $nodeKey,
        public string $type,
        public ?DateTimeImmutable $resumeAt,
    ) {
    }
}
