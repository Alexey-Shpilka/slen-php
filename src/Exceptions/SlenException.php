<?php

declare(strict_types=1);

namespace Slen\Sdk\Exceptions;

use RuntimeException;
use Throwable;

/** The root of every error the SDK raises: a refusal of the API, or no readable answer at all. */
class SlenException extends RuntimeException
{
    /** @param array<string, mixed> $details `error.details`, or `errors` of a framework validation answer */
    public function __construct(
        string $message,
        public readonly ?string $errorCode = null,
        public readonly ?int $statusCode = null,
        public readonly array $details = [],
        public readonly ?string $requestId = null,
        public readonly ?int $retryAfterSeconds = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** The type of the error contract (`contract/error-codes.json`): `RateLimit` for RateLimitException. */
    public static function type(): string
    {
        $class = static::class;

        return substr($class, (int) strrpos($class, '\\') + 1, -\strlen('Exception'));
    }
}
