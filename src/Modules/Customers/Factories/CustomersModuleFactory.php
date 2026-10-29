<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Customers\Factories;

use Slen\Sdk\Modules\Customers\Resources\CreditEntryResource;
use Slen\Sdk\Modules\Customers\Resources\CreditPriceResource;
use Slen\Sdk\Modules\Customers\Resources\CustomerAccountResource;
use Slen\Sdk\Modules\Customers\Resources\CustomerResource;
use Slen\Sdk\Modules\Customers\Tasks\BlockCustomerTask;
use Slen\Sdk\Modules\Customers\Tasks\CreateCustomerTask;
use Slen\Sdk\Modules\Customers\Tasks\DeleteCustomerTask;
use Slen\Sdk\Modules\Customers\Tasks\GetCustomerTask;
use Slen\Sdk\Modules\Customers\Tasks\ListCustomersTask;
use Slen\Sdk\Modules\Customers\Tasks\ListEntriesTask;
use Slen\Sdk\Modules\Customers\Tasks\ListPricesTask;
use Slen\Sdk\Modules\Customers\Tasks\MoveCreditsTask;
use Slen\Sdk\Modules\Customers\Tasks\UnblockCustomerTask;
use Slen\Sdk\Modules\Customers\Tasks\UpdateAccountTask;
use Slen\Sdk\Shared\Http\ApiTransport;

/** @internal */
final readonly class CustomersModuleFactory
{
    public function __construct(private ApiTransport $apiTransport)
    {
    }

    public function listCustomersTask(): ListCustomersTask
    {
        return new ListCustomersTask($this->apiTransport, $this->customerResource());
    }

    public function getCustomerTask(): GetCustomerTask
    {
        return new GetCustomerTask($this->apiTransport, $this->customerResource());
    }

    public function createCustomerTask(): CreateCustomerTask
    {
        return new CreateCustomerTask($this->apiTransport, $this->customerResource());
    }

    public function updateAccountTask(): UpdateAccountTask
    {
        return new UpdateAccountTask($this->apiTransport, new CustomerAccountResource());
    }

    public function blockCustomerTask(): BlockCustomerTask
    {
        return new BlockCustomerTask($this->apiTransport, $this->customerResource());
    }

    public function unblockCustomerTask(): UnblockCustomerTask
    {
        return new UnblockCustomerTask($this->apiTransport, $this->customerResource());
    }

    public function deleteCustomerTask(): DeleteCustomerTask
    {
        return new DeleteCustomerTask($this->apiTransport);
    }

    public function moveCreditsTask(): MoveCreditsTask
    {
        return new MoveCreditsTask($this->apiTransport, new CustomerAccountResource());
    }

    public function listEntriesTask(): ListEntriesTask
    {
        return new ListEntriesTask($this->apiTransport, new CreditEntryResource());
    }

    public function listPricesTask(): ListPricesTask
    {
        return new ListPricesTask($this->apiTransport, new CreditPriceResource());
    }

    private function customerResource(): CustomerResource
    {
        return new CustomerResource(new CustomerAccountResource());
    }
}
