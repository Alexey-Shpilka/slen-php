<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Customers\Data;

use DateTimeImmutable;

/** The credits of one type a customer holds. Credits are decimal strings, never floats. */
final readonly class CustomerAccount
{
    /**
     * @param string $available           `balance - reserved`: what a new hold may take
     * @param string $lowBalanceThreshold below it a movement sends `customer.balance_low`; `0` is off
     */
    public function __construct(
        public string $customerAccountId,
        public string $customerId,
        public string $projectId,
        public string $externalUserId,
        public string $creditType,
        public string $balance,
        public string $reserved,
        public string $available,
        public bool $allowNegative,
        public string $lowBalanceThreshold,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
