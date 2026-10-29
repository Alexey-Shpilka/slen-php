<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Webhooks\Data;

use DateTimeImmutable;
use Slen\Sdk\Modules\Webhooks\Enums\WebhookEventType;

/** A webhook whose signature held. Every delivery of one event carries the same $id: deduplicate by it. */
abstract readonly class WebhookEventDTO
{
    /** @param array<mixed> $data */
    public function __construct(
        public string $id,
        public string $type,
        public DateTimeImmutable $createdAt,
        public array $data,
    ) {
    }

    /** The type as this SDK knows it; null for a type newer than the SDK. */
    public function eventType(): ?WebhookEventType
    {
        return WebhookEventType::tryFrom($this->type);
    }
}
