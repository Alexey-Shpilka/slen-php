<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Customers\Data;

use Slen\Sdk\Modules\Customers\Enums\CreditMovement;
use Slen\Sdk\Modules\Customers\Options\MovementOptions;

/** @internal */
final readonly class CreditMovementDTO
{
    public function __construct(
        public CreditMovement $movement,
        public string $externalUserId,
        public string $credits,
        public MovementOptions $options,
    ) {
    }
}
