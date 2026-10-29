<?php

declare(strict_types=1);

namespace Slen\Sdk\Exceptions;

/** `insufficient_credits`: the customer has fewer credits than the operation costs. */
class InsufficientCreditsException extends SpendRefusedException
{
}
