<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Customers\Options;

/** The settings of an account, put whole. */
final readonly class AccountOptions
{
    /**
     * @param string      $lowBalanceThreshold credits; below it a movement sends `customer.balance_low`, '0' is off
     * @param float|null  $timeout             seconds each attempt may take, above zero; the config's when null
     * @param int|null    $retries             attempts after the first, zero or more; the config's when null
     * @param string|null $requestId           8 to 128 of A-Za-z0-9._:-, the X-Request-Id of every attempt; the server's own when null
     */
    public function __construct(
        public bool $allowNegative = false,
        public string $lowBalanceThreshold = '0',
        public ?float $timeout = null,
        public ?int $retries = null,
        public ?string $requestId = null,
    ) {
    }
}
