<?php

declare(strict_types=1);

namespace Slen\Sdk\Exceptions;

/** `operation_uncertain`: a provider was reached and did not answer in time; whether money was spent is unknown. */
class OperationUncertainException extends SlenException
{
}
