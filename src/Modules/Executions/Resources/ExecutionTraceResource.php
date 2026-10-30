<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Executions\Resources;

use Slen\Sdk\Exceptions\InvalidResponseException;
use Slen\Sdk\Modules\Executions\Data\AttemptSummary;
use Slen\Sdk\Modules\Executions\Data\ExecutionTrace;
use Slen\Sdk\Modules\Executions\Data\FlowExecutionEvent;
use Slen\Sdk\Modules\Executions\Data\FlowExecutionNode;
use Slen\Sdk\Modules\Operations\Resources\ExecutionResource;
use Slen\Sdk\Shared\Resources\BaseResource;

/** @internal */
final readonly class ExecutionTraceResource extends BaseResource
{
    public function __construct(private ExecutionResource $executionResource)
    {
    }

    /**
     * @param array<mixed> $data
     *
     * @throws InvalidResponseException
     */
    public function map(array $data): ExecutionTrace
    {
        return new ExecutionTrace(
            executionId: $this->string($data, 'execution_id'),
            flowId: $this->string($data, 'flow_id'),
            flowVersionId: $this->string($data, 'flow_version_id'),
            flowName: $this->nullableString($data, 'flow_name'),
            operationCode: $this->nullableString($data, 'operation'),
            operationId: $this->nullableString($data, 'operation_id'),
            status: $this->executionResource->status($data),
            errorCode: $this->nullableString($data, 'error_code'),
            errorMessage: $this->nullableString($data, 'error_message'),
            cost: $this->executionResource->cost($data),
            cache: $this->executionResource->cache($data),
            startedAt: $this->dateTime($data, 'started_at'),
            finishedAt: $this->nullableDateTime($data, 'finished_at'),
            durationMs: $this->nullableInt($data, 'duration_ms'),
            nodes: array_map($this->node(...), $this->objects($data, 'nodes')),
            events: array_map($this->event(...), $this->objects($data, 'events')),
        );
    }

    /** @param array<mixed> $node */
    private function node(array $node): FlowExecutionNode
    {
        return new FlowExecutionNode(
            nodeKey: $this->string($node, 'node_key'),
            nodeType: $this->string($node, 'node_type'),
            status: $this->string($node, 'status'),
            outputPort: $this->nullableString($node, 'output_port'),
            cost: $this->nullableDecimal($node, 'cost'),
            durationMs: $this->nullableInt($node, 'duration_ms'),
            startedAt: $this->dateTime($node, 'started_at'),
            finishedAt: $this->nullableDateTime($node, 'finished_at'),
            metadata: $this->nullableArray($node, 'metadata'),
            attempts: array_map($this->attempt(...), $this->objects($node, 'attempts')),
            label: $this->nullableString($node, 'label'),
            model: $this->nullableString($node, 'model'),
            code: $this->nullableString($node, 'code'),
        );
    }

    /** @param array<mixed> $attempt */
    private function attempt(array $attempt): AttemptSummary
    {
        return new AttemptSummary(
            attemptId: $this->string($attempt, 'attempt_id'),
            attemptNumber: $this->int($attempt, 'attempt_number'),
            connectionId: $this->string($attempt, 'connection_id'),
            poolId: $this->nullableString($attempt, 'pool_id'),
            status: $this->string($attempt, 'status'),
            errorCode: $this->nullableString($attempt, 'error_code'),
            latencyMs: $this->nullableInt($attempt, 'latency_ms'),
            cost: $this->nullableDecimal($attempt, 'cost'),
            providerRequestId: $this->nullableString($attempt, 'provider_request_id'),
            modelCode: $this->nullableString($attempt, 'model_code'),
        );
    }

    /** @param array<mixed> $event */
    private function event(array $event): FlowExecutionEvent
    {
        return new FlowExecutionEvent(
            seq: $this->int($event, 'seq'),
            type: $this->string($event, 'type'),
            nodeKey: $this->nullableString($event, 'node_key'),
            occurredAt: $this->dateTime($event, 'occurred_at'),
            payload: $this->nullableArray($event, 'payload'),
        );
    }
}
