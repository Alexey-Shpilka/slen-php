<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Webhooks\Data;

use DateTimeImmutable;
use Slen\Sdk\Modules\Webhooks\Enums\WebhookEventType;

/** `customer.balance_low`: a movement took the credits of an account below its threshold. */
final readonly class CustomerBalanceLowEventDTO extends WebhookEventDTO
{
    /**
     * @param array<mixed> $data
     * @param string       $available $balance less $reserved, after the movement
     */
    public function __construct(
        string $id,
        DateTimeImmutable $createdAt,
        array $data,
        public string $customerAccountId,
        public string $projectId,
        public string $externalUserId,
        public string $creditType,
        public string $balance,
        public string $reserved,
        public string $available,
        public string $threshold,
    ) {
        parent::__construct($id, WebhookEventType::CustomerBalanceLow->value, $createdAt, $data);
    }
}
