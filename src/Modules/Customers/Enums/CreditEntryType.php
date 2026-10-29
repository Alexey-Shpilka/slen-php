<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Customers\Enums;

/** The six ways credits move; only `commit` is spend, a `charge` is the application's own. */
enum CreditEntryType: string
{
    case Hold = 'hold';
    case Commit = 'commit';
    case Release = 'release';
    case TopUp = 'topup';
    case Adjust = 'adjust';
    case Charge = 'charge';
}
