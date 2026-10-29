<?php

declare(strict_types=1);

namespace Slen\Sdk\Exceptions;

/** `concurrency_limit_exceeded`: the budget already holds as many runs at once as it allows. */
class ConcurrencyLimitException extends SpendRefusedException
{
}
