<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Executions\Data;

use Slen\Sdk\Modules\Operations\Data\ExecutionCacheDTO;
use Slen\Sdk\Modules\Operations\Data\ExecutionCostDTO;
use Slen\Sdk\Modules\Operations\Data\WaitingOnDTO;
use Slen\Sdk\Modules\Operations\Enums\ExecutionStatus;

/** One run as a poll reads it: the same fields, with the same meaning, as the answer of execute(). */
final readonly class ExecutionStatusDTO
{
    /**
     * @param array<mixed>|null $output            what the flow returned; null until it returned something
     * @param int|null          $retryAfterSeconds while the run is not over: when to read it again
     */
    public function __construct(
        public string $executionId,
        public ExecutionStatus $status,
        public string $operationCode,
        public ?array $output,
        public ?string $errorCode,
        public ?string $errorMessage,
        public ExecutionCostDTO $cost,
        public ExecutionCacheDTO $cache,
        public ?WaitingOnDTO $waitingOn,
        public string $traceUrl,
        public ?int $retryAfterSeconds,
    ) {
    }

    public function isCompleted(): bool
    {
        return $this->status === ExecutionStatus::Completed;
    }

    /** Not over yet: queued, waiting or running. */
    public function isPending(): bool
    {
        return $this->status->isPending();
    }

    public function isFinished(): bool
    {
        return $this->status->isFinished();
    }

    /** The run ended in a refusal: $errorCode says which. */
    public function isRejected(): bool
    {
        return $this->status === ExecutionStatus::Rejected;
    }
}
