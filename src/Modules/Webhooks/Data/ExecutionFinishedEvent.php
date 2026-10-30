<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Webhooks\Data;

use DateTimeImmutable;
use Slen\Sdk\Modules\Operations\Enums\ExecutionStatus;
use Slen\Sdk\Modules\Webhooks\Enums\WebhookEventType;

/** `execution.finished`: a run is over. */
final readonly class ExecutionFinishedEvent extends WebhookEvent
{
    /**
     * @param array<mixed> $data
     * @param string|null  $errorCode why it did not complete; null for a completed run
     */
    public function __construct(
        string $id,
        DateTimeImmutable $createdAt,
        array $data,
        public string $executionId,
        public string $flowId,
        public string $operationCode,
        public ExecutionStatus $status,
        public ?string $errorCode,
    ) {
        parent::__construct($id, WebhookEventType::ExecutionFinished->value, $createdAt, $data);
    }
}
