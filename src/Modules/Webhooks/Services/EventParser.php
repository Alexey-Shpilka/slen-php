<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Webhooks\Services;

use DateTimeImmutable;
use JsonException;
use Slen\Sdk\Exceptions\InvalidResponseException;
use Slen\Sdk\Modules\Operations\Enums\ExecutionStatus;
use Slen\Sdk\Modules\Webhooks\Data\ApprovalDecidedEventDTO;
use Slen\Sdk\Modules\Webhooks\Data\ApprovalRequestedEventDTO;
use Slen\Sdk\Modules\Webhooks\Data\CustomerBalanceLowEventDTO;
use Slen\Sdk\Modules\Webhooks\Data\ExecutionFinishedEventDTO;
use Slen\Sdk\Modules\Webhooks\Data\UnknownEventDTO;
use Slen\Sdk\Modules\Webhooks\Data\WebhookEventDTO;
use Slen\Sdk\Modules\Webhooks\Enums\WebhookEventType;
use Slen\Sdk\Shared\Resources\BaseResource;
use Throwable;

/** @internal */
final readonly class EventParser extends BaseResource
{
    /** @throws InvalidResponseException `webhook_payload_invalid`: not JSON, or not an event the SDK can read */
    public function parse(string $payload): WebhookEventDTO
    {
        try {
            $event = json_decode($payload, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);

            return $this->event(\is_array($event) === true ? $event : []);
        } catch (JsonException | InvalidResponseException $throwable) {
            throw $this->notAnEvent($throwable);
        }
    }

    /**
     * @param array<mixed> $event
     *
     * @throws InvalidResponseException
     */
    private function event(array $event): WebhookEventDTO
    {
        $id = $this->string($event, 'id');
        $type = $this->string($event, 'type');
        $createdAt = $this->dateTime($event, 'created_at');
        $data = $this->array($event, 'data');

        return match (WebhookEventType::tryFrom($type)) {
            WebhookEventType::ExecutionFinished => $this->executionFinished($id, $createdAt, $data),
            WebhookEventType::CustomerBalanceLow => $this->customerBalanceLow($id, $createdAt, $data),
            WebhookEventType::ApprovalRequested => $this->approvalRequested($id, $createdAt, $data),
            WebhookEventType::ApprovalDecided => $this->approvalDecided($id, $createdAt, $data),
            default => new UnknownEventDTO($id, $type, $createdAt, $data),
        };
    }

    /** @param array<mixed> $data */
    private function executionFinished(string $id, DateTimeImmutable $createdAt, array $data): ExecutionFinishedEventDTO
    {
        return new ExecutionFinishedEventDTO(
            $id,
            $createdAt,
            $data,
            executionId: $this->string($data, 'execution_id'),
            flowId: $this->string($data, 'flow_id'),
            operationCode: $this->string($data, 'operation'),
            status: $this->enum($data, 'status', ExecutionStatus::class),
            errorCode: $this->nullableString($data, 'error_code'),
        );
    }

    /** @param array<mixed> $data */
    private function customerBalanceLow(string $id, DateTimeImmutable $createdAt, array $data): CustomerBalanceLowEventDTO
    {
        return new CustomerBalanceLowEventDTO(
            $id,
            $createdAt,
            $data,
            customerAccountId: $this->string($data, 'customer_account_id'),
            projectId: $this->string($data, 'project_id'),
            externalUserId: $this->string($data, 'external_user_id'),
            creditType: $this->string($data, 'credit_type'),
            balance: $this->decimal($data, 'balance'),
            reserved: $this->decimal($data, 'reserved'),
            available: $this->decimal($data, 'available'),
            threshold: $this->decimal($data, 'threshold'),
        );
    }

    /** @param array<mixed> $data */
    private function approvalRequested(string $id, DateTimeImmutable $createdAt, array $data): ApprovalRequestedEventDTO
    {
        return new ApprovalRequestedEventDTO(
            $id,
            $createdAt,
            $data,
            approvalId: $this->string($data, 'approval_id'),
            executionId: $this->string($data, 'execution_id'),
            flowId: $this->string($data, 'flow_id'),
            nodeKey: $this->string($data, 'node_key'),
            operationCode: $this->string($data, 'operation_code'),
            note: $this->nullableString($data, 'note'),
            expiresAt: $this->dateTime($data, 'expires_at'),
        );
    }

    /** @param array<mixed> $data */
    private function approvalDecided(string $id, DateTimeImmutable $createdAt, array $data): ApprovalDecidedEventDTO
    {
        return new ApprovalDecidedEventDTO(
            $id,
            $createdAt,
            $data,
            approvalId: $this->string($data, 'approval_id'),
            executionId: $this->string($data, 'execution_id'),
            flowId: $this->string($data, 'flow_id'),
            nodeKey: $this->string($data, 'node_key'),
            operationCode: $this->string($data, 'operation_code'),
            status: $this->string($data, 'status'),
            reason: $this->nullableString($data, 'reason'),
            decidedBy: $this->nullableString($data, 'decided_by'),
            decidedByUserId: $this->nullableString($data, 'decided_by_user_id'),
            decidedAt: $this->nullableDateTime($data, 'decided_at'),
        );
    }

    private function notAnEvent(Throwable $throwable): InvalidResponseException
    {
        return new InvalidResponseException(
            'Slen: the body of the webhook is signed but is not an event the SDK can read.',
            'webhook_payload_invalid',
            previous: $throwable,
        );
    }
}
