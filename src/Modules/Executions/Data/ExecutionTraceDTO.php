<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Executions\Data;

use DateTimeImmutable;
use Slen\Sdk\Modules\Operations\Data\ExecutionCacheDTO;
use Slen\Sdk\Modules\Operations\Data\ExecutionCostDTO;
use Slen\Sdk\Modules\Operations\Enums\ExecutionStatus;

/** The story of one run: the run itself, every node it walked and every event, in order. */
final readonly class ExecutionTraceDTO
{
    /**
     * @param string|null         $flowName      the flow's name as it is now; null for a flow out of reach
     * @param list<TraceNodeDTO>  $nodes
     * @param list<TraceEventDTO> $events
     */
    public function __construct(
        public string $executionId,
        public string $flowId,
        public string $flowVersionId,
        public ?string $flowName,
        public ?string $operationCode,
        public ?string $operationId,
        public ExecutionStatus $status,
        public ?string $errorCode,
        public ?string $errorMessage,
        public ExecutionCostDTO $cost,
        public ExecutionCacheDTO $cache,
        public DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $finishedAt,
        public ?int $durationMs,
        public array $nodes,
        public array $events,
    ) {
    }
}
