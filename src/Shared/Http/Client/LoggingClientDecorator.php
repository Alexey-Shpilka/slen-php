<?php

declare(strict_types=1);

namespace Slen\Sdk\Shared\Http\Client;

use Slen\Sdk\Exceptions\InvalidResponseException;
use Slen\Sdk\Shared\Http\Data\ApiRequestDTO;
use Slen\Sdk\Shared\Http\Data\ApiResponseDTO;
use Slen\Sdk\Shared\Http\Data\RejectionDTO;
use Slen\Sdk\Shared\Http\Mappers\ErrorMapper;

/** @internal */
final readonly class LoggingClientDecorator implements ApiClientInterface
{
    public function __construct(
        private ApiClientInterface $apiClient,
        private HttpJournal $httpJournal,
        private ErrorMapper $errorMapper,
    ) {
    }

    public function send(ApiRequestDTO $request): ApiResponseDTO
    {
        $started = hrtime(true);
        $response = $this->apiClient->send($request);
        $requestId = $response->requestId();

        if ($response->readable === false) {
            $type = InvalidResponseException::type();
            $this->httpJournal->rejected($request, new RejectionDTO($response->status, 'invalid_response', $requestId, $type, $response->attempt));

            return $response;
        }

        if ($response->isSuccessful() === true) {
            $this->httpJournal->response($request, $response, intdiv(hrtime(true) - $started, 1_000_000));

            return $response;
        }

        $code = $response->errorCode();
        $type = $this->errorMapper->classFor($code, $response->status)::type();
        $this->httpJournal->rejected($request, new RejectionDTO($response->status, $code, $requestId, $type, $response->attempt));

        return $response;
    }
}
