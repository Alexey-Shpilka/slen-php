<?php

declare(strict_types=1);

namespace Slen\Sdk\Exceptions;

/** `customer_blocked`: the end customer named in `context.user_id` is blocked, so its new runs are refused. Nothing ran. */
class CustomerBlockedException extends PolicyDeniedException
{
}
