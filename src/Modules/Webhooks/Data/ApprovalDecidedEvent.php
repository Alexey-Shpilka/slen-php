<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Webhooks\Data;

use DateTimeImmutable;
use Slen\Sdk\Modules\Webhooks\Enums\WebhookEventType;

/** `approval.decided`: an approval was approved, rejected or expired. */
final readonly class ApprovalDecidedEvent extends WebhookEvent
{
    /**
     * @param array<mixed>           $data
     * @param string                 $status          approved, rejected or expired
     * @param string|null            $decidedBy       the API key that decided; null for a person or an expiry
     * @param string|null            $decidedByUserId the person who decided; null for a key or an expiry
     * @param DateTimeImmutable|null $decidedAt       null for an expiry
     */
    public function __construct(
        string $id,
        DateTimeImmutable $createdAt,
        array $data,
        public string $approvalId,
        public string $executionId,
        public string $flowId,
        public string $nodeKey,
        public string $operationCode,
        public string $status,
        public ?string $reason,
        public ?string $decidedBy,
        public ?string $decidedByUserId,
        public ?DateTimeImmutable $decidedAt,
    ) {
        parent::__construct($id, WebhookEventType::ApprovalDecided->value, $createdAt, $data);
    }
}
