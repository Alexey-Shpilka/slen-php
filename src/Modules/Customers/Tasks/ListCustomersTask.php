<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Customers\Tasks;

use Slen\Sdk\Exceptions\SlenException;
use Slen\Sdk\Modules\Customers\Data\CustomerPageDTO;
use Slen\Sdk\Modules\Customers\Options\CustomerListOptions;
use Slen\Sdk\Modules\Customers\Resources\CustomerResource;
use Slen\Sdk\Shared\Http\ApiTransport;
use Slen\Sdk\Shared\Http\Data\ApiRequestDTO;
use Slen\Sdk\Shared\Http\Enums\Endpoint;
use Slen\Sdk\Shared\Tasks\AbstractTask;

/** @internal */
final readonly class ListCustomersTask extends AbstractTask
{
    public function __construct(
        ApiTransport $apiTransport,
        private CustomerResource $customerResource,
    ) {
        parent::__construct($apiTransport);
    }

    /** @throws SlenException */
    public function execute(CustomerListOptions $options): CustomerPageDTO
    {
        // Without the view an older API answers the page of accounts.
        $query = [
            'view' => 'customers',
            'cursor' => $options->cursor,
            'limit' => $options->limit,
            'q' => $options->search,
            'status' => $options->status?->value,
            'credit_type' => $options->creditType,
            'low_balance' => $options->lowBalance,
        ];
        $apiRequest = new ApiRequestDTO(Endpoint::ListCustomers, query: $query, retries: $options->retries, timeout: $options->timeout);

        return $this->customerResource->page($this->exchange($apiRequest)->body);
    }
}
