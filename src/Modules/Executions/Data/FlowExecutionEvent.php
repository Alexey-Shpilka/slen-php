<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Executions\Data;

use DateTimeImmutable;

/** An event of the run; events are ordered by $seq, not by the clock. */
final readonly class FlowExecutionEvent
{
    /**
     * @param string            $type    accepted, node_started, reserved, provider_completed, finished, …
     * @param array<mixed>|null $payload
     */
    public function __construct(
        public int $seq,
        public string $type,
        public ?string $nodeKey,
        public DateTimeImmutable $occurredAt,
        public ?array $payload,
    ) {
    }
}
