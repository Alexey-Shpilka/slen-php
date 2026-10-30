<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Operations\Resources;

use Slen\Sdk\Exceptions\InvalidResponseException;
use Slen\Sdk\Modules\Operations\Data\ExecutionCache;
use Slen\Sdk\Modules\Operations\Data\ExecutionCost;
use Slen\Sdk\Modules\Operations\Data\ExecutionError;
use Slen\Sdk\Modules\Operations\Data\ExecutionResult;
use Slen\Sdk\Modules\Operations\Data\WaitingOn;
use Slen\Sdk\Modules\Operations\Enums\ExecutionStatus;
use Slen\Sdk\Shared\Resources\BaseResource;

/** @internal */
final readonly class ExecutionResource extends BaseResource
{
    /**
     * @param array<mixed> $data
     *
     * @throws InvalidResponseException
     */
    public function map(array $data): ExecutionResult
    {
        $error = $this->nullableArray($data, 'error');

        return new ExecutionResult(
            executionId: $this->string($data, 'execution_id'),
            status: $this->status($data),
            operationCode: $this->string($data, 'operation'),
            output: $this->nullableArray($data, 'output'),
            errorCode: $this->nullableString($data, 'error_code'),
            errorMessage: $this->nullableString($data, 'error_message'),
            cost: $this->cost($data),
            cache: $this->cache($data),
            waitingOn: $this->waitingOn($data),
            traceUrl: $this->string($data, 'trace_url'),
            retryAfterSeconds: $this->nullableInt($data, 'retry_after_seconds'),
            error: $error === null ? null : new ExecutionError($this->string($error, 'code'), $this->string($error, 'message')),
            replayed: $this->bool($data, 'replayed'),
        );
    }

    /**
     * @param array<mixed> $data
     *
     * @throws InvalidResponseException
     */
    public function status(array $data): ExecutionStatus
    {
        return $this->enum($data, 'status', ExecutionStatus::class);
    }

    /**
     * @param array<mixed> $data
     *
     * @throws InvalidResponseException
     */
    public function cost(array $data): ExecutionCost
    {
        $cost = $this->array($data, 'cost');

        return new ExecutionCost($this->nullableDecimal($cost, 'estimated'), $this->nullableDecimal($cost, 'actual'));
    }

    /**
     * @param array<mixed> $data
     *
     * @throws InvalidResponseException
     */
    public function cache(array $data): ExecutionCache
    {
        $cache = $this->array($data, 'cache');

        return new ExecutionCache($this->bool($cache, 'hit'), $this->nullableDateTime($cache, 'cached_at'));
    }

    /**
     * @param array<mixed> $data
     *
     * @throws InvalidResponseException
     */
    public function waitingOn(array $data): ?WaitingOn
    {
        $waitingOn = $this->nullableArray($data, 'waiting_on');

        if ($waitingOn === null) {
            return null;
        }

        return new WaitingOn(
            $this->string($waitingOn, 'node_key'),
            $this->string($waitingOn, 'type'),
            $this->nullableDateTime($waitingOn, 'resume_at'),
        );
    }
}
