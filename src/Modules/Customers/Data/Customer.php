<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Customers\Data;

use DateTimeImmutable;
use Slen\Sdk\Modules\Customers\Enums\CustomerStatus;

/** An end customer of the project with every credit account it holds, one per credit type. */
final readonly class Customer
{
    /**
     * @param DateTimeImmutable|null $blockedAt when it was first blocked; null while it is active
     * @param list<CustomerAccount>  $accounts
     */
    public function __construct(
        public string $customerId,
        public string $externalUserId,
        public CustomerStatus $status,
        public ?DateTimeImmutable $blockedAt,
        public ?string $blockReason,
        public array $accounts,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?string $requestId = null,
    ) {
    }

    public function isBlocked(): bool
    {
        return $this->status === CustomerStatus::Blocked;
    }

    /** The account of $creditType, or null when the customer holds none of that type. */
    public function account(string $creditType = 'default'): ?CustomerAccount
    {
        foreach ($this->accounts as $account) {
            if ($account->creditType === $creditType) {
                return $account;
            }
        }

        return null;
    }
}
