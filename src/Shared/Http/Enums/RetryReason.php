<?php

declare(strict_types=1);

namespace Slen\Sdk\Shared\Http\Enums;

/** @internal */
enum RetryReason: string
{
    case NoAnswer = 'no_answer';
    case RateLimited = 'rate_limited';
    case Unavailable = 'unavailable';
}
