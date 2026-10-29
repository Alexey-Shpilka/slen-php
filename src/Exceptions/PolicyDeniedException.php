<?php

declare(strict_types=1);

namespace Slen\Sdk\Exceptions;

/** A policy of the project refused the call (`policy_denied`, `model_not_allowed`). Nothing ran. */
class PolicyDeniedException extends SlenException
{
}
