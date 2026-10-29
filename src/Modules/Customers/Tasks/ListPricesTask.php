<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Customers\Tasks;

use Slen\Sdk\Exceptions\SlenException;
use Slen\Sdk\Modules\Customers\Data\CreditPriceDTO;
use Slen\Sdk\Modules\Customers\Options\PriceListOptions;
use Slen\Sdk\Modules\Customers\Resources\CreditPriceResource;
use Slen\Sdk\Shared\Http\ApiTransport;
use Slen\Sdk\Shared\Http\Data\ApiRequestDTO;
use Slen\Sdk\Shared\Http\Enums\Endpoint;
use Slen\Sdk\Shared\Tasks\AbstractTask;

/** @internal */
final readonly class ListPricesTask extends AbstractTask
{
    public function __construct(
        ApiTransport $apiTransport,
        private CreditPriceResource $creditPriceResource,
    ) {
        parent::__construct($apiTransport);
    }

    /**
     * @return list<CreditPriceDTO>
     *
     * @throws SlenException
     */
    public function execute(PriceListOptions $options): array
    {
        $apiRequest = new ApiRequestDTO(
            Endpoint::ListPrices,
            query: ['operation_code' => $options->operationCode, 'credit_type' => $options->creditType],
            retries: $options->retries,
            timeout: $options->timeout,
        );

        return $this->creditPriceResource->list($this->exchange($apiRequest)->body);
    }
}
