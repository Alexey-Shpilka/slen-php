<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Customers\Tasks;

use Slen\Sdk\Exceptions\SlenException;
use Slen\Sdk\Modules\Customers\Data\CustomerDTO;
use Slen\Sdk\Modules\Customers\Resources\CustomerResource;
use Slen\Sdk\RequestOptions;
use Slen\Sdk\Shared\Http\ApiTransport;
use Slen\Sdk\Shared\Http\Data\ApiRequestDTO;
use Slen\Sdk\Shared\Http\Enums\Endpoint;
use Slen\Sdk\Shared\Tasks\AbstractTask;

/** @internal */
final readonly class UnblockCustomerTask extends AbstractTask
{
    public function __construct(
        ApiTransport $apiTransport,
        private CustomerResource $customerResource,
    ) {
        parent::__construct($apiTransport);
    }

    /** @throws SlenException */
    public function execute(string $externalUserId, RequestOptions $options): CustomerDTO
    {
        $apiRequest = new ApiRequestDTO(
            Endpoint::UnblockCustomer,
            ['externalUserId' => $externalUserId],
            body: [],
            retries: $options->retries,
            timeout: $options->timeout,
        );

        return $this->customerResource->map($this->send($apiRequest));
    }
}
