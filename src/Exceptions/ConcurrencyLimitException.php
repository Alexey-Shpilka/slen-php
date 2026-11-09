<?php

declare(strict_types=1);

namespace Slen\Sdk\Exceptions;

/** `concurrency_limit_exceeded`: a Queue node's limit of calls at once is full and its `busy` port leads nowhere; `details` is that port's output. */
class ConcurrencyLimitException extends SpendRefusedException
{
}
