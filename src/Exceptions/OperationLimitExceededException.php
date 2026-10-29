<?php

declare(strict_types=1);

namespace Slen\Sdk\Exceptions;

/** `operation_limit_exceeded`: one call would cost more than the budget allows a single operation. */
class OperationLimitExceededException extends SpendRefusedException
{
}
