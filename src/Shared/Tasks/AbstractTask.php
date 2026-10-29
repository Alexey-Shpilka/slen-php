<?php

declare(strict_types=1);

namespace Slen\Sdk\Shared\Tasks;

use Slen\Sdk\Exceptions\InvalidResponseException;
use Slen\Sdk\Exceptions\SlenException;
use Slen\Sdk\IdempotencyKeys;
use Slen\Sdk\Shared\Http\ApiTransport;
use Slen\Sdk\Shared\Http\Data\ApiRequestDTO;
use Slen\Sdk\Shared\Http\Data\ApiResponseDTO;

/** @internal */
abstract readonly class AbstractTask
{
    public function __construct(protected ApiTransport $apiTransport)
    {
    }

    /** @throws SlenException */
    protected function exchange(ApiRequestDTO $request): ApiResponseDTO
    {
        $response = $this->apiTransport->client->send($request);

        if ($response->isSuccessful() === false) {
            throw $this->apiTransport->errorMapper->map($response);
        }

        if ($response->readable === false) {
            throw new InvalidResponseException(
                \sprintf('Slen answered %d to %s with a body that cannot be read. The call may have gone through.', $response->status, $request->describe()),
                'invalid_response',
                $response->status,
                requestId: $response->requestId(),
            );
        }

        return $response;
    }

    /**
     * @return array<mixed>
     *
     * @throws SlenException
     */
    protected function send(ApiRequestDTO $request): array
    {
        $body = $this->exchange($request)->body;

        if ($request->envelope === false) {
            return $body;
        }

        return \is_array($body['data'] ?? null) === true ? $body['data'] : [];
    }

    protected function idempotencyKey(?string $key): string
    {
        return $key ?? IdempotencyKeys::generate();
    }
}
