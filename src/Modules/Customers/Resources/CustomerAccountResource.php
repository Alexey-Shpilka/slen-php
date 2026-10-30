<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Customers\Resources;

use Slen\Sdk\Exceptions\InvalidResponseException;
use Slen\Sdk\Modules\Customers\Data\CustomerAccount;
use Slen\Sdk\Shared\Resources\BaseResource;

/** @internal */
final readonly class CustomerAccountResource extends BaseResource
{
    /**
     * @param array<mixed> $data
     *
     * @throws InvalidResponseException
     */
    public function map(array $data, ?string $requestId = null): CustomerAccount
    {
        return new CustomerAccount(
            customerAccountId: $this->string($data, 'customer_account_id'),
            customerId: $this->string($data, 'customer_id'),
            projectId: $this->string($data, 'project_id'),
            externalUserId: $this->string($data, 'external_user_id'),
            creditType: $this->string($data, 'credit_type'),
            balance: $this->decimal($data, 'balance'),
            reserved: $this->decimal($data, 'reserved'),
            available: $this->decimal($data, 'available'),
            allowNegative: $this->bool($data, 'allow_negative'),
            lowBalanceThreshold: $this->decimal($data, 'low_balance_threshold'),
            createdAt: $this->dateTime($data, 'created_at'),
            updatedAt: $this->dateTime($data, 'updated_at'),
            requestId: $requestId,
        );
    }
}
