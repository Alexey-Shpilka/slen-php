<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Customers\Tasks;

use Slen\Sdk\Exceptions\InvalidRequestException;
use Slen\Sdk\Exceptions\SlenException;
use Slen\Sdk\IdempotencyKeys;
use Slen\Sdk\Modules\Customers\Data\CreditMovementDTO;
use Slen\Sdk\Modules\Customers\Data\CustomerAccount;
use Slen\Sdk\Modules\Customers\Resources\CustomerAccountResource;
use Slen\Sdk\Shared\Http\ApiCaller;
use Slen\Sdk\Shared\Http\Data\ApiRequestDTO;

/** @internal */
final readonly class MoveCreditsTask
{
    private const string CREDITS = '/^-?\d+(\.\d{1,4})?$/';

    public function __construct(
        private ApiCaller $apiCaller,
        private CustomerAccountResource $customerAccountResource,
    ) {
    }

    /**
     * @throws InvalidRequestException nothing sent: credits that are not an amount this movement takes,
     *                                 an empty customer, credit type or key
     * @throws SlenException
     */
    public function execute(CreditMovementDTO $creditMovementDTO): CustomerAccount
    {
        $creditMovement = $creditMovementDTO->movement;
        $credits = $creditMovementDTO->credits;
        $options = $creditMovementDTO->options;
        $route = $creditMovement->endpoint()->value;

        if (preg_match(self::CREDITS, $credits) !== 1) {
            throw $this->refused(\sprintf('the credits of %s are not a decimal string of at most four places', $route));
        }

        // Zero in any spelling: no digit but 0.
        $zero = trim($credits, '-0.') === '';

        if ($creditMovement->isSigned() === true && $zero === true) {
            throw $this->refused(\sprintf('the credits of %s are zero; a correction moves something', $route));
        }

        if ($creditMovement->isSigned() === false && ($zero === true || str_starts_with($credits, '-') === true)) {
            throw $this->refused(\sprintf('the credits of %s are not above zero', $route));
        }

        if (trim($options->creditType) === '') {
            throw $this->refused(\sprintf('the creditType of %s is empty', $route));
        }

        $body = ['credits' => $credits, 'credit_type' => $options->creditType];

        if ($options->note !== null) {
            $body['note'] = $options->note;
        }

        $apiRequest = new ApiRequestDTO(
            $creditMovement->endpoint(),
            ['externalUserId' => $creditMovementDTO->externalUserId],
            body: $body,
            idempotencyKey: $options->idempotencyKey ?? IdempotencyKeys::generate(),
            retries: $options->retries,
            timeout: $options->timeout,
            requestId: $options->requestId,
        );
        $apiResponse = $this->apiCaller->exchange($apiRequest);

        return $this->customerAccountResource->map($apiResponse->data(), $apiResponse->requestId());
    }

    private function refused(string $what): InvalidRequestException
    {
        return new InvalidRequestException('Slen: ' . $what . '.', 'invalid_request');
    }
}
