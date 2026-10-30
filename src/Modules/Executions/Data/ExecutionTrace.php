<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Executions\Data;

use DateTimeImmutable;
use Slen\Sdk\Modules\Operations\Data\ExecutionCache;
use Slen\Sdk\Modules\Operations\Data\ExecutionCost;
use Slen\Sdk\Modules\Operations\Enums\ExecutionStatus;

/** The story of one run: the run itself, every node it walked and every event, in order. */
final readonly class ExecutionTrace
{
    /**
     * @param string|null              $flowName the flow's name as it is now; null for a flow out of reach
     * @param list<FlowExecutionNode>  $nodes
     * @param list<FlowExecutionEvent> $events
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
        public ExecutionCost $cost,
        public ExecutionCache $cache,
        public DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $finishedAt,
        public ?int $durationMs,
        public array $nodes,
        public array $events,
        public ?string $requestId = null,
    ) {
    }
}
