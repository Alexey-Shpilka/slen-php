<?php

declare(strict_types=1);

namespace Slen\Sdk\Exceptions;

/** Money said no (402): a budget, a limit of the operation, runs at once, or the credits of a customer. */
class SpendRefusedException extends SlenException
{
}
