<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Operations\Tasks;

use Slen\Sdk\Exceptions\InvalidRequestException;
use Slen\Sdk\Exceptions\SlenException;
use Slen\Sdk\Modules\Operations\Data\ContextClaimDTO;
use Slen\Sdk\Modules\Operations\Data\ExecuteRequestDTO;
use Slen\Sdk\Modules\Operations\Data\ExecutionDTO;
use Slen\Sdk\Modules\Operations\Resources\ExecutionResource;
use Slen\Sdk\Shared\Http\ApiTransport;
use Slen\Sdk\Shared\Http\Data\ApiRequestDTO;
use Slen\Sdk\Shared\Http\Enums\Endpoint;
use Slen\Sdk\Shared\Tasks\AbstractTask;
use stdClass;

/** @internal */
final readonly class ExecuteOperationTask extends AbstractTask
{
    public function __construct(
        ApiTransport $apiTransport,
        private ExecutionResource $executionResource,
    ) {
        parent::__construct($apiTransport);
    }

    /** @throws InvalidRequestException */
    public function request(ExecuteRequestDTO $executeRequestDTO): ApiRequestDTO
    {
        $options = $executeRequestDTO->options;
        $context = $this->reserve($options->context, new ContextClaimDTO('user_id', 'customer', $options->customer));
        $context = $this->reserve($context, new ContextClaimDTO('credit_type', 'creditType', $options->creditType));

        $input = $executeRequestDTO->input;
        // An empty input is an object on the wire, as the spec has it.
        $body = ['operation' => $executeRequestDTO->operation, 'input' => $input === [] ? new stdClass() : $input];

        if ($context !== []) {
            $body['context'] = $context;
        }

        if ($executeRequestDTO->async === true) {
            $body['execution_mode'] = 'async';
        }

        return new ApiRequestDTO(
            Endpoint::ExecuteOperation,
            body: $body,
            idempotencyKey: $this->idempotencyKey($options->idempotencyKey),
            retries: $options->retries,
            timeout: $options->timeout,
        );
    }

    /** @throws SlenException */
    public function execute(ApiRequestDTO $request): ExecutionDTO
    {
        return $this->executionResource->map($this->send($request));
    }

    /**
     * @param array<string, scalar|null> $context
     *
     * @return array<string, scalar|null>
     *
     * @throws InvalidRequestException
     */
    private function reserve(array $context, ContextClaimDTO $contextClaimDTO): array
    {
        $value = $contextClaimDTO->value;
        $key = $contextClaimDTO->key;

        if ($value === null) {
            return $context;
        }

        if (trim($value) === '') {
            throw $this->refused(\sprintf('the %s of %s is empty; pass one that is not, or none', $contextClaimDTO->option, Endpoint::ExecuteOperation->value));
        }

        if (isset($context[$key]) && (string) $context[$key] !== $value) {
            throw $this->refused(
                \sprintf('%s and context.%s of %s differ; pass one of them', $contextClaimDTO->option, $key, Endpoint::ExecuteOperation->value),
            );
        }

        $context[$key] = $value;

        return $context;
    }

    private function refused(string $what): InvalidRequestException
    {
        return new InvalidRequestException('Slen: ' . $what . '.', 'invalid_request');
    }
}
