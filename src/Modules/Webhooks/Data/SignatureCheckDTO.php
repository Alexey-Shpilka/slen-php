<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Webhooks\Data;

/** @internal */
final readonly class SignatureCheckDTO
{
    public function __construct(
        public ?string $reason,
        public ?int $ageSeconds,
    ) {
    }
}
