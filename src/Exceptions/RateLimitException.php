<?php

declare(strict_types=1);

namespace Slen\Sdk\Exceptions;

/** The API key sent more requests than its rate limit allows (429); nothing ran. */
class RateLimitException extends SlenException
{
}
