<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Webhooks\Data;

/** An event the SDK has no class for: any other of WebhookEventType, or a type newer than the SDK. Read $type and the raw $data. */
final readonly class UnknownWebhookEvent extends WebhookEvent
{
}
