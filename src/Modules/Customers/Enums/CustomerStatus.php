<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Customers\Enums;

/** A `blocked` customer's new runs are refused; its balance can still be moved and its statement read. */
enum CustomerStatus: string
{
    case Active = 'active';
    case Blocked = 'blocked';
}
