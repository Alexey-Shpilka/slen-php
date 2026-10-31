<?php

declare(strict_types=1);

namespace Slen\Sdk\Shared\Http;

use Slen\Sdk\Exceptions\InvalidResponseException;
use Slen\Sdk\Exceptions\SlenException;
use Slen\Sdk\Shared\Http\Data\ApiRequestDTO;
use Slen\Sdk\Shared\Http\Data\ApiResponseDTO;

/** @internal */
final readonly class ApiCaller
{
    public function __construct(private ApiTransport $apiTransport)
    {
    }

    /** @throws SlenException */
    public function exchange(ApiRequestDTO $request): ApiResponseDTO
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
}
