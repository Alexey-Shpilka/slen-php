<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Customers\Factories;

use Slen\Sdk\Modules\Customers\Resources\CreditEntryResource;
use Slen\Sdk\Modules\Customers\Resources\CreditPriceResource;
use Slen\Sdk\Modules\Customers\Resources\CustomerAccountResource;
use Slen\Sdk\Modules\Customers\Resources\CustomerResource;
use Slen\Sdk\Modules\Customers\Tasks\DeleteCustomerTask;
use Slen\Sdk\Modules\Customers\Tasks\MoveCreditsTask;
use Slen\Sdk\Shared\Http\ApiCaller;
use Slen\Sdk\Shared\Http\ApiTransport;

/** @internal */
final readonly class CustomersModuleFactory
{
    public function __construct(private ApiTransport $apiTransport)
    {
    }

    public function api(): ApiCaller
    {
        return new ApiCaller($this->apiTransport);
    }

    public function customerResource(): CustomerResource
    {
        return new CustomerResource($this->customerAccountResource());
    }

    public function customerAccountResource(): CustomerAccountResource
    {
        return new CustomerAccountResource();
    }

    public function creditEntryResource(): CreditEntryResource
    {
        return new CreditEntryResource();
    }

    public function creditPriceResource(): CreditPriceResource
    {
        return new CreditPriceResource();
    }

    public function deleteCustomerTask(): DeleteCustomerTask
    {
        return new DeleteCustomerTask($this->apiTransport);
    }

    public function moveCreditsTask(): MoveCreditsTask
    {
        return new MoveCreditsTask($this->api(), $this->customerAccountResource());
    }
}
