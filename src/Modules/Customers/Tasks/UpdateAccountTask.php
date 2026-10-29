<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Customers\Tasks;

use Slen\Sdk\Exceptions\SlenException;
use Slen\Sdk\Modules\Customers\Data\CustomerAccountDTO;
use Slen\Sdk\Modules\Customers\Options\AccountOptions;
use Slen\Sdk\Modules\Customers\Resources\CustomerAccountResource;
use Slen\Sdk\Shared\Http\ApiTransport;
use Slen\Sdk\Shared\Http\Data\ApiRequestDTO;
use Slen\Sdk\Shared\Http\Enums\Endpoint;
use Slen\Sdk\Shared\Tasks\AbstractTask;

/** @internal */
final readonly class UpdateAccountTask extends AbstractTask
{
    public function __construct(
        ApiTransport $apiTransport,
        private CustomerAccountResource $customerAccountResource,
    ) {
        parent::__construct($apiTransport);
    }

    /** @throws SlenException */
    public function execute(string $externalUserId, string $creditType, AccountOptions $options): CustomerAccountDTO
    {
        $apiRequest = new ApiRequestDTO(
            Endpoint::UpdateAccount,
            ['externalUserId' => $externalUserId, 'creditType' => $creditType],
            body: ['allow_negative' => $options->allowNegative, 'low_balance_threshold' => $options->lowBalanceThreshold],
            retries: $options->retries,
            timeout: $options->timeout,
        );

        return $this->customerAccountResource->map($this->send($apiRequest));
    }
}
