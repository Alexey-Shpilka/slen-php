<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Webhooks\Data;

use DateTimeImmutable;
use Slen\Sdk\Modules\Webhooks\Enums\WebhookEventType;

/** `approval.requested`: a run parked at an approval node and waits for a person. */
final readonly class ApprovalRequestedEvent extends WebhookEvent
{
    /** @param array<mixed> $data */
    public function __construct(
        string $id,
        DateTimeImmutable $createdAt,
        array $data,
        public string $approvalId,
        public string $executionId,
        public string $flowId,
        public string $nodeKey,
        public string $operationCode,
        public ?string $note,
        public DateTimeImmutable $expiresAt,
    ) {
        parent::__construct($id, WebhookEventType::ApprovalRequested->value, $createdAt, $data);
    }
}
