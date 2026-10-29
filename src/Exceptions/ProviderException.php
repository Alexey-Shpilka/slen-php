<?php

declare(strict_types=1);

namespace Slen\Sdk\Exceptions;

/** A model provider failed, or a 5xx came without a code; with `errorCode === null` the outcome is unknown. */
class ProviderException extends SlenException
{
}
