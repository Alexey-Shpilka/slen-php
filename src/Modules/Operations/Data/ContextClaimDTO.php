<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Operations\Data;

/** @internal */
final readonly class ContextClaimDTO
{
    public function __construct(
        public string $key,
        public string $option,
        public ?string $value,
    ) {
    }
}
