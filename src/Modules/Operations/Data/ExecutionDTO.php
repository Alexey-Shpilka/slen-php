<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Operations\Data;

use Slen\Sdk\Modules\Operations\Enums\ExecutionStatus;

/** The answer of a run: finished (completed, rejected, failed, uncertain) or not yet (queued, waiting, running). */
final readonly class ExecutionDTO
{
    /**
     * @param array<mixed>|null         $output            what the flow returned; null until it returned something
     * @param int|null                  $retryAfterSeconds while the run is not over: when to read it again
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
        public ?ExecutionErrorDTO $error,
        public bool $replayed,
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

    /** The flow said no, as an answer: $errorCode says why. A platform refusal is thrown instead. */
    public function isRejected(): bool
    {
        return $this->status === ExecutionStatus::Rejected;
    }
}
