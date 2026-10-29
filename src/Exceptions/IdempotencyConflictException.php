<?php

declare(strict_types=1);

namespace Slen\Sdk\Exceptions;

/** The idempotency key belongs to another request, or to one still in progress. Use a new key for a different request; wait and repeat for the same one. */
class IdempotencyConflictException extends SlenException
{
}
