<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Executions\Data;

use DateTimeImmutable;

/** A node the run walked, written once it finished. */
final readonly class FlowExecutionNode
{
    /**
     * @param string               $status     completed or failed
     * @param string|null          $outputPort the edge it left by; null for a terminal node
     * @param string|null          $cost       a decimal string of money
     * @param array<mixed>|null    $metadata
     * @param list<AttemptSummary> $attempts
     */
    public function __construct(
        public string $nodeKey,
        public string $nodeType,
        public string $status,
        public ?string $outputPort,
        public ?string $cost,
        public ?int $durationMs,
        public DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $finishedAt,
        public ?array $metadata,
        public array $attempts,
        public ?string $label,
        public ?string $model,
        public ?string $code,
    ) {
    }
}
