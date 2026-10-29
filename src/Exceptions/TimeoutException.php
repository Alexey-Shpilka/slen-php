<?php

declare(strict_types=1);

namespace Slen\Sdk\Exceptions;

/** No answer within the timeout of the client, or the run itself ran out of time. */
class TimeoutException extends SlenException
{
}
