<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Executions\Resources;

use Slen\Sdk\Exceptions\InvalidResponseException;
use Slen\Sdk\Modules\Executions\Data\ExecutionStatusView;
use Slen\Sdk\Modules\Operations\Resources\ExecutionResource;
use Slen\Sdk\Shared\Resources\BaseResource;

/** @internal */
final readonly class ExecutionStatusResource extends BaseResource
{
    public function __construct(private ExecutionResource $executionResource)
    {
    }

    /**
     * @param array<mixed> $data
     *
     * @throws InvalidResponseException
     */
    public function map(array $data): ExecutionStatusView
    {
        return new ExecutionStatusView(
            executionId: $this->string($data, 'execution_id'),
            status: $this->executionResource->status($data),
            operationCode: $this->string($data, 'operation'),
            output: $this->nullableArray($data, 'output'),
            errorCode: $this->nullableString($data, 'error_code'),
            errorMessage: $this->nullableString($data, 'error_message'),
            cost: $this->executionResource->cost($data),
            cache: $this->executionResource->cache($data),
            waitingOn: $this->executionResource->waitingOn($data),
            traceUrl: $this->string($data, 'trace_url'),
            retryAfterSeconds: $this->nullableInt($data, 'retry_after_seconds'),
        );
    }
}
