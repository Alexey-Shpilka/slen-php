<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Customers\Tasks;

use Slen\Sdk\Exceptions\SlenException;
use Slen\Sdk\RequestOptions;
use Slen\Sdk\Shared\Http\Data\ApiRequestDTO;
use Slen\Sdk\Shared\Http\Enums\Endpoint;
use Slen\Sdk\Shared\Tasks\AbstractTask;

/** @internal */
final readonly class DeleteCustomerTask extends AbstractTask
{
    /** @throws SlenException */
    public function execute(string $externalUserId, RequestOptions $options): void
    {
        $apiRequest = new ApiRequestDTO(
            Endpoint::DeleteCustomer,
            ['externalUserId' => $externalUserId],
            envelope: false,
            retries: $options->retries,
            timeout: $options->timeout,
        );
        $apiResponse = $this->apiTransport->client->send($apiRequest);

        // An attempt that got no answer may have deleted the customer; the repeat then finds none.
        if ($apiResponse->status === 404 && $apiResponse->afterNoAnswer === true && $apiResponse->errorCode() === 'customer_not_found') {
            return;
        }

        if ($apiResponse->isSuccessful() === false) {
            throw $this->apiTransport->errorMapper->map($apiResponse);
        }
    }
}
