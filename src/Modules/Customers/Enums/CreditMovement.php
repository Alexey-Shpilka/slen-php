<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Customers\Enums;

use Slen\Sdk\Shared\Http\Enums\Endpoint;

/** @internal */
enum CreditMovement: string
{
    case TopUp = 'topup';
    case Charge = 'charge';
    case Adjust = 'adjust';

    public function endpoint(): Endpoint
    {
        return match ($this) {
            self::TopUp => Endpoint::TopUp,
            self::Charge => Endpoint::Charge,
            self::Adjust => Endpoint::Adjust,
        };
    }

    public function isSigned(): bool
    {
        return $this === self::Adjust;
    }
}
