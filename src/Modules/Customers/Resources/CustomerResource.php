<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Customers\Resources;

use Slen\Sdk\Data\Page;
use Slen\Sdk\Exceptions\InvalidResponseException;
use Slen\Sdk\Modules\Customers\Data\Customer;
use Slen\Sdk\Modules\Customers\Enums\CustomerStatus;
use Slen\Sdk\Shared\Resources\BaseResource;

/** @internal */
final readonly class CustomerResource extends BaseResource
{
    public function __construct(private CustomerAccountResource $customerAccountResource)
    {
    }

    /**
     * @param array<mixed> $data
     *
     * @throws InvalidResponseException
     */
    public function map(array $data): Customer
    {
        return new Customer(
            customerId: $this->string($data, 'customer_id'),
            externalUserId: $this->string($data, 'external_user_id'),
            status: $this->enum($data, 'status', CustomerStatus::class),
            blockedAt: $this->nullableDateTime($data, 'blocked_at'),
            blockReason: $this->nullableString($data, 'block_reason'),
            accounts: array_map($this->customerAccountResource->map(...), $this->objects($data, 'accounts')),
            createdAt: $this->dateTime($data, 'created_at'),
            updatedAt: $this->dateTime($data, 'updated_at'),
        );
    }

    /**
     * @param array<mixed> $body
     *
     * @return Page<Customer>
     *
     * @throws InvalidResponseException
     */
    public function page(array $body): Page
    {
        return new Page(
            array_map($this->map(...), $this->objects($body, 'data')),
            $this->nullableString($this->array($body, 'meta'), 'next_cursor'),
        );
    }
}
