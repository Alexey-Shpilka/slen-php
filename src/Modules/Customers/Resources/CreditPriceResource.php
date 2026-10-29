<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Customers\Resources;

use Slen\Sdk\Exceptions\InvalidResponseException;
use Slen\Sdk\Modules\Customers\Data\CreditPriceDTO;
use Slen\Sdk\Shared\Resources\BaseResource;

/** @internal */
final readonly class CreditPriceResource extends BaseResource
{
    /**
     * @param array<mixed> $data
     *
     * @throws InvalidResponseException
     */
    public function map(array $data): CreditPriceDTO
    {
        return new CreditPriceDTO(
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
     * @return list<CreditPriceDTO>
     *
     * @throws InvalidResponseException
     */
    public function list(array $body): array
    {
        return array_map($this->map(...), $this->objects($body, 'data'));
    }
}
