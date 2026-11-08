<?php

declare(strict_types=1);

namespace Slen\Sdk\Exceptions;

/** A policy of the project refused the call (`policy_denied`), or the model is not one it may call (`model_not_allowed`, `model_retired`). Nothing ran. */
class PolicyDeniedException extends SlenException
{
}
