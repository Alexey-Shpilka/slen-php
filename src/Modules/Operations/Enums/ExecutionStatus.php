<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Operations\Enums;

/** Where a run of a flow stands. `queued`, `waiting` and `running` are not over yet; the rest are. */
enum ExecutionStatus: string
{
    case Running = 'running';
    case Queued = 'queued';
    case Waiting = 'waiting';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Failed = 'failed';
    case Uncertain = 'uncertain';

    /** The run will still move on its own: a worker, a delay or a person comes first. */
    public function isPending(): bool
    {
        return match ($this) {
            self::Queued, self::Waiting, self::Running => true,
            default => false,
        };
    }

    public function isFinished(): bool
    {
        return $this->isPending() === false;
    }
}
