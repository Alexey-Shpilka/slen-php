<?php

declare(strict_types=1);

namespace Slen\Sdk\Exceptions;

/** `budget_exceeded`: a budget of the project or the organization has no room for the call. */
class BudgetExceededException extends SpendRefusedException
{
}
