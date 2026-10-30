<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Customers;

use Generator;
use Slen\Sdk\Data\Page;
use Slen\Sdk\Exceptions\CustomerNotFoundException;
use Slen\Sdk\Exceptions\IdempotencyConflictException;
use Slen\Sdk\Exceptions\InsufficientCreditsException;
use Slen\Sdk\Exceptions\InvalidRequestException;
use Slen\Sdk\Exceptions\InvalidResponseException;
use Slen\Sdk\Exceptions\SlenException;
use Slen\Sdk\Modules\Customers\Data\CreditEntry;
use Slen\Sdk\Modules\Customers\Data\CreditMovementDTO;
use Slen\Sdk\Modules\Customers\Data\CreditPriceList;
use Slen\Sdk\Modules\Customers\Data\Customer;
use Slen\Sdk\Modules\Customers\Data\CustomerAccount;
use Slen\Sdk\Modules\Customers\Enums\CreditMovement;
use Slen\Sdk\Modules\Customers\Factories\CustomersModuleFactory;
use Slen\Sdk\Modules\Customers\Options\AccountOptions;
use Slen\Sdk\Modules\Customers\Options\BlockOptions;
use Slen\Sdk\Modules\Customers\Options\CustomerListOptions;
use Slen\Sdk\Modules\Customers\Options\EntryListOptions;
use Slen\Sdk\Modules\Customers\Options\MovementOptions;
use Slen\Sdk\Modules\Customers\Options\PriceListOptions;
use Slen\Sdk\RequestOptions;
use Slen\Sdk\Shared\Http\CursorPager;
use Slen\Sdk\Shared\Http\Data\ApiRequestDTO;
use Slen\Sdk\Shared\Http\Enums\Endpoint;

/** The end customers of the project, named by your own opaque id: their status, accounts and credits. */
final readonly class CustomersFacade
{
    /** @internal build it with SlenSdk::customers() */
    public function __construct(private CustomersModuleFactory $customersModuleFactory)
    {
    }

    /**
     * One page of customers, newest first, each with all its accounts.
     *
     * @return Page<Customer>
     *
     * @throws InvalidRequestException a timeout or retries out of range; nothing sent
     * @throws SlenException
     */
    public function list(?CustomerListOptions $options = null): Page
    {
        $options ??= new CustomerListOptions();

        return $this->customerPage($options, $options->cursor);
    }

    /**
     * Every customer from the page of `cursor` on, `limit` a page, read as the loop goes; nothing sent before it does.
     *
     * @return Generator<int, Customer, mixed, void>
     *
     * @throws InvalidRequestException  nothing sent: a requestId (one id names one request), a timeout or retries out of range
     * @throws InvalidResponseException a page whose cursor is the one it was asked with
     * @throws SlenException
     */
    public function listAll(?CustomerListOptions $options = null): Generator
    {
        $options ??= new CustomerListOptions();

        return $this->everyPage(Endpoint::ListCustomers, $options->requestId)->pages(function (?string $cursor) use ($options): Page {
            return $this->customerPage($options, $cursor);
        }, $options->cursor);
    }

    /**
     * One customer with its status and its accounts by credit type.
     *
     * @throws InvalidRequestException   nothing sent: an empty id, a timeout or retries out of range
     * @throws CustomerNotFoundException the project has no such customer
     * @throws SlenException
     */
    public function get(string $externalUserId, ?RequestOptions $options = null): Customer
    {
        $options ??= new RequestOptions();

        return $this->customer(new ApiRequestDTO(
            Endpoint::GetCustomer,
            ['externalUserId' => $externalUserId],
            retries: $options->retries,
            timeout: $options->timeout,
            requestId: $options->requestId,
        ));
    }

    /**
     * Makes sure the customer exists, with no account and no credits; a customer that exists is answered as it is.
     *
     * @throws InvalidRequestException nothing sent: an empty id, a timeout or retries out of range
     * @throws SlenException
     */
    public function create(string $externalUserId, ?RequestOptions $options = null): Customer
    {
        $options ??= new RequestOptions();

        return $this->customer(new ApiRequestDTO(
            Endpoint::CreateCustomer,
            ['externalUserId' => $externalUserId],
            body: [],
            retries: $options->retries,
            timeout: $options->timeout,
            requestId: $options->requestId,
        ));
    }

    /**
     * Puts the settings of the account of $creditType, created with the customer when they are not there yet.
     *
     * @throws InvalidRequestException nothing sent: an empty id or credit type, a timeout or retries out of range
     * @throws SlenException
     */
    public function updateAccount(string $externalUserId, string $creditType, ?AccountOptions $options = null): CustomerAccount
    {
        $options ??= new AccountOptions();
        $apiRequest = new ApiRequestDTO(
            Endpoint::UpdateAccount,
            ['externalUserId' => $externalUserId, 'creditType' => $creditType],
            body: ['allow_negative' => $options->allowNegative, 'low_balance_threshold' => $options->lowBalanceThreshold],
            retries: $options->retries,
            timeout: $options->timeout,
            requestId: $options->requestId,
        );
        $apiResponse = $this->customersModuleFactory->api()->exchange($apiRequest);

        return $this->customersModuleFactory->customerAccountResource()->map($apiResponse->data(), $apiResponse->requestId());
    }

    /**
     * New runs for the customer are refused with CustomerBlockedException; its balance can still be moved.
     *
     * @throws InvalidRequestException   nothing sent: an empty id, a timeout or retries out of range
     * @throws CustomerNotFoundException the project has no such customer
     * @throws SlenException
     */
    public function block(string $externalUserId, ?BlockOptions $options = null): Customer
    {
        $options ??= new BlockOptions();

        return $this->customer(new ApiRequestDTO(
            Endpoint::BlockCustomer,
            ['externalUserId' => $externalUserId],
            body: $options->reason === null ? [] : ['reason' => $options->reason],
            retries: $options->retries,
            timeout: $options->timeout,
            requestId: $options->requestId,
        ));
    }

    /**
     * The customer is active again.
     *
     * @throws InvalidRequestException   nothing sent: an empty id, a timeout or retries out of range
     * @throws CustomerNotFoundException the project has no such customer
     * @throws SlenException
     */
    public function unblock(string $externalUserId, ?RequestOptions $options = null): Customer
    {
        $options ??= new RequestOptions();

        return $this->customer(new ApiRequestDTO(
            Endpoint::UnblockCustomer,
            ['externalUserId' => $externalUserId],
            body: [],
            retries: $options->retries,
            timeout: $options->timeout,
            requestId: $options->requestId,
        ));
    }

    /**
     * Deletes a customer nothing ever happened to; one with a statement is refused (`customer_has_history`): block it.
     *
     * @throws InvalidRequestException   nothing sent: an empty id, a timeout or retries out of range; also the refusal of
     *                                   a customer with history
     * @throws CustomerNotFoundException the project has no such customer
     * @throws SlenException
     */
    public function delete(string $externalUserId, ?RequestOptions $options = null): void
    {
        $this->customersModuleFactory->deleteCustomerTask()->execute($externalUserId, $options ?? new RequestOptions());
    }

    /**
     * Adds credits, creating the customer and the account when they are not there yet.
     *
     * @param string $credits above zero, at most four decimal places: '100.0000'
     *
     * @throws InvalidRequestException      nothing sent: credits that are not an amount above zero, an empty id, credit type
     *                                      or key, a timeout or retries out of range
     * @throws IdempotencyConflictException the key names another movement
     * @throws SlenException
     */
    public function topUp(string $externalUserId, string $credits, ?MovementOptions $options = null): CustomerAccount
    {
        return $this->move(new CreditMovementDTO(CreditMovement::TopUp, $externalUserId, $credits, $options ?? new MovementOptions()));
    }

    /**
     * Takes credits for a service of the application, down to what is held unless the account may go negative.
     *
     * @param string $credits the amount to take: above zero, at most four decimal places
     *
     * @throws InvalidRequestException      nothing sent: credits that are not an amount above zero, an empty id, credit type
     *                                      or key, a timeout or retries out of range
     * @throws CustomerNotFoundException    the project has no such customer
     * @throws InsufficientCreditsException the account cannot cover it, or the customer holds none of the credit type
     * @throws IdempotencyConflictException the key names another movement
     * @throws SlenException
     */
    public function charge(string $externalUserId, string $credits, ?MovementOptions $options = null): CustomerAccount
    {
        return $this->move(new CreditMovementDTO(CreditMovement::Charge, $externalUserId, $credits, $options ?? new MovementOptions()));
    }

    /**
     * Corrects the balance.
     *
     * @param string $credits signed, never zero: '-5.0000' takes credits away
     *
     * @throws InvalidRequestException      nothing sent: credits that are not a signed amount other than zero, an empty id,
     *                                      credit type or key, a timeout or retries out of range
     * @throws CustomerNotFoundException    the project has no such customer
     * @throws InsufficientCreditsException a negative correction the account cannot cover
     * @throws IdempotencyConflictException the key names another movement
     * @throws SlenException
     */
    public function adjust(string $externalUserId, string $credits, ?MovementOptions $options = null): CustomerAccount
    {
        return $this->move(new CreditMovementDTO(CreditMovement::Adjust, $externalUserId, $credits, $options ?? new MovementOptions()));
    }

    /**
     * One page of the customer's statement, newest first: every movement with the balance it left behind.
     *
     * @return Page<CreditEntry>
     *
     * @throws InvalidRequestException   nothing sent: an empty id, a timeout or retries out of range
     * @throws CustomerNotFoundException the project has no such customer
     * @throws SlenException
     */
    public function entries(string $externalUserId, ?EntryListOptions $options = null): Page
    {
        $options ??= new EntryListOptions();

        return $this->entryPage($externalUserId, $options, $options->cursor);
    }

    /**
     * The statement from the page of `cursor` on, `limit` a page, read as the loop goes; nothing sent before it does.
     *
     * @return Generator<int, CreditEntry, mixed, void>
     *
     * @throws InvalidRequestException   nothing sent: an empty id, a requestId, a timeout or retries out of range
     * @throws CustomerNotFoundException the project has no such customer
     * @throws InvalidResponseException  a page whose cursor is the one it was asked with
     * @throws SlenException
     */
    public function entriesAll(string $externalUserId, ?EntryListOptions $options = null): Generator
    {
        $options ??= new EntryListOptions();

        return $this->everyPage(Endpoint::ListEntries, $options->requestId)->pages(function (?string $cursor) use ($externalUserId, $options): Page {
            return $this->entryPage($externalUserId, $options, $cursor);
        }, $options->cursor);
    }

    /**
     * What one run of an operation holds from its customer, by operation code and credit type.
     *
     * @throws InvalidRequestException a timeout or retries out of range; nothing sent
     * @throws SlenException
     */
    public function prices(?PriceListOptions $options = null): CreditPriceList
    {
        $options ??= new PriceListOptions();
        $apiRequest = new ApiRequestDTO(
            Endpoint::ListPrices,
            query: ['operation_code' => $options->operationCode, 'credit_type' => $options->creditType],
            retries: $options->retries,
            timeout: $options->timeout,
            requestId: $options->requestId,
        );
        $apiResponse = $this->customersModuleFactory->api()->exchange($apiRequest);

        return $this->customersModuleFactory->creditPriceResource()->list($apiResponse->body, $apiResponse->requestId());
    }

    /** @throws SlenException */
    private function move(CreditMovementDTO $creditMovementDTO): CustomerAccount
    {
        return $this->customersModuleFactory->moveCreditsTask()->execute($creditMovementDTO);
    }

    /** @throws InvalidRequestException */
    private function everyPage(Endpoint $endpoint, ?string $requestId): CursorPager
    {
        // An id names one request, and every page is a request of its own.
        if ($requestId !== null) {
            throw new InvalidRequestException(
                \sprintf('Slen: a request id names one request, and each page of %s is one; give it to a call of one page.', $endpoint->value),
                'invalid_request',
            );
        }

        return $this->customersModuleFactory->cursorPager($endpoint);
    }

    /**
     * @return Page<Customer>
     *
     * @throws SlenException
     */
    private function customerPage(CustomerListOptions $options, ?string $cursor): Page
    {
        // Without the view an older API answers the page of accounts.
        $query = [
            'view' => 'customers',
            'cursor' => $cursor,
            'limit' => $options->limit,
            'q' => $options->search,
            'status' => $options->status?->value,
            'credit_type' => $options->creditType,
            'low_balance' => $options->lowBalance,
        ];
        $apiRequest = new ApiRequestDTO(
            Endpoint::ListCustomers,
            query: $query,
            retries: $options->retries,
            timeout: $options->timeout,
            requestId: $options->requestId,
        );
        $apiResponse = $this->customersModuleFactory->api()->exchange($apiRequest);

        return $this->customersModuleFactory->customerResource()->page($apiResponse->body, $apiResponse->requestId());
    }

    /**
     * @return Page<CreditEntry>
     *
     * @throws SlenException
     */
    private function entryPage(string $externalUserId, EntryListOptions $options, ?string $cursor): Page
    {
        $apiRequest = new ApiRequestDTO(
            Endpoint::ListEntries,
            ['externalUserId' => $externalUserId],
            [
                'cursor' => $cursor,
                'limit' => $options->limit,
                'credit_type' => $options->creditType,
                'entry_type' => $options->entryType?->value,
            ],
            retries: $options->retries,
            timeout: $options->timeout,
            requestId: $options->requestId,
        );
        $apiResponse = $this->customersModuleFactory->api()->exchange($apiRequest);

        return $this->customersModuleFactory->creditEntryResource()->page($apiResponse->body, $apiResponse->requestId());
    }

    /** @throws SlenException */
    private function customer(ApiRequestDTO $apiRequest): Customer
    {
        $apiResponse = $this->customersModuleFactory->api()->exchange($apiRequest);

        return $this->customersModuleFactory->customerResource()->map($apiResponse->data(), $apiResponse->requestId());
    }
}
