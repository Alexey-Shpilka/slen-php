<?php

declare(strict_types=1);

namespace Slen\Sdk\Exceptions;

/** `customer_not_found`: no customer with that external user id in this project. */
class CustomerNotFoundException extends SlenException
{
}
