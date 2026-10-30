<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Executions\Data;

use Slen\Sdk\Modules\Operations\Data\ExecutionCache;
use Slen\Sdk\Modules\Operations\Data\ExecutionCost;
use Slen\Sdk\Modules\Operations\Data\WaitingOn;
use Slen\Sdk\Modules\Operations\Enums\ExecutionStatus;

/** One run as a poll reads it: the same fields, with the same meaning, as the answer of execute(). */
final readonly class ExecutionStatusView
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
        public ExecutionCost $cost,
        public ExecutionCache $cache,
        public ?WaitingOn $waitingOn,
        public string $traceUrl,
        public ?int $retryAfterSeconds,
        public ?string $requestId = null,
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
