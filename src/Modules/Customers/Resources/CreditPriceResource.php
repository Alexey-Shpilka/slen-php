<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Customers\Resources;

use Slen\Sdk\Exceptions\InvalidResponseException;
use Slen\Sdk\Modules\Customers\Data\CreditPrice;
use Slen\Sdk\Modules\Customers\Data\CreditPriceList;
use Slen\Sdk\Shared\Resources\BaseResource;

/** @internal */
final readonly class CreditPriceResource extends BaseResource
{
    /**
     * @param array<mixed> $data
     *
     * @throws InvalidResponseException
     */
    public function map(array $data): CreditPrice
    {
        return new CreditPrice(
            creditPriceId: $this->string($data, 'credit_price_id'),
            projectId: $this->string($data, 'project_id'),
            operationCode: $this->string($data, 'operation_code'),
            creditType: $this->string($data, 'credit_type'),
            credits: $this->decimal($data, 'credits'),
            createdAt: $this->dateTime($data, 'created_at'),
            updatedAt: $this->dateTime($data, 'updated_at'),
        );
    }

    /**
     * @param array<mixed> $body
     *
     * @throws InvalidResponseException
     */
    public function list(array $body): CreditPriceList
    {
        return new CreditPriceList(array_map($this->map(...), $this->objects($body, 'data')));
    }
}
