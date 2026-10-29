<?php

declare(strict_types=1);

namespace Slen\Sdk\Exceptions;

/** `webhook_signature_invalid`: the Slen-Signature of a webhook does not hold; $reason names the check. */
class WebhookSignatureException extends SlenException
{
    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message, 'webhook_signature_invalid', details: ['reason' => $reason]);
    }
}
