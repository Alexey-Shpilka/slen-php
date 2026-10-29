<?php

declare(strict_types=1);

namespace Slen\Sdk\Exceptions;

/** Slen itself could not serve the call (`service_unavailable`, `ledger_unavailable`); nothing was spent. */
class ServiceUnavailableException extends SlenException
{
}
