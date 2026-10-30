<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Operations\Options;

/** The options of a run: whom it is for, what it draws on, its key and its time. */
final readonly class ExecuteOptions
{
    /**
     * @param string|null                $customer       the end customer, sent as context.user_id
     * @param string|null                $creditType     the credits the run draws on, sent as context.credit_type
     * @param array<string, scalar|null> $context
     * @param string|null                $idempotencyKey one per unit of work; generated when null
     * @param float|null                 $timeout        seconds each attempt may take, above zero; the config's when null
     * @param int|null                   $retries        attempts after the first, zero or more; the config's when null
     * @param string|null                $requestId      8 to 128 of A-Za-z0-9._:-, the X-Request-Id of every attempt; the server's own when null
     */
    public function __construct(
        public ?string $customer = null,
        public ?string $creditType = null,
        public array $context = [],
        public ?string $idempotencyKey = null,
        public ?float $timeout = null,
        public ?int $retries = null,
        public ?string $requestId = null,
    ) {
    }
}
