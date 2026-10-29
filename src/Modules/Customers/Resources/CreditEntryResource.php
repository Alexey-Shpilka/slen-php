<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Customers\Resources;

use Slen\Sdk\Exceptions\InvalidResponseException;
use Slen\Sdk\Modules\Customers\Data\CreditEntryDTO;
use Slen\Sdk\Modules\Customers\Data\CreditEntryPageDTO;
use Slen\Sdk\Modules\Customers\Enums\CreditEntryType;
use Slen\Sdk\Shared\Resources\BaseResource;

/** @internal */
final readonly class CreditEntryResource extends BaseResource
{
    /**
     * @param array<mixed> $data
     *
     * @throws InvalidResponseException
     */
    public function map(array $data): CreditEntryDTO
    {
        return new CreditEntryDTO(
            entryId: $this->string($data, 'entry_id'),
            customerAccountId: $this->string($data, 'customer_account_id'),
            externalUserId: $this->string($data, 'external_user_id'),
            creditType: $this->string($data, 'credit_type'),
            entryType: $this->enum($data, 'entry_type', CreditEntryType::class),
            credits: $this->decimal($data, 'credits'),
            balanceAfter: $this->decimal($data, 'balance_after'),
            availableAfter: $this->decimal($data, 'available_after'),
            reservationId: $this->nullableString($data, 'reservation_id'),
            note: $this->nullableString($data, 'note'),
            createdAt: $this->dateTime($data, 'created_at'),
        );
    }

    /**
     * @param array<mixed> $body
     *
     * @throws InvalidResponseException
     */
    public function page(array $body): CreditEntryPageDTO
    {
        return new CreditEntryPageDTO(
            array_map($this->map(...), $this->objects($body, 'data')),
            $this->nullableString($this->array($body, 'meta'), 'next_cursor'),
        );
    }
}
