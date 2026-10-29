<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Executions\Tasks;

use Slen\Sdk\Exceptions\InvalidRequestException;
use Slen\Sdk\Exceptions\SlenException;
use Slen\Sdk\Modules\Executions\Data\ExecutionStatusDTO;
use Slen\Sdk\Modules\Executions\Resources\ExecutionStatusResource;
use Slen\Sdk\RequestOptions;
use Slen\Sdk\Shared\Http\ApiTransport;
use Slen\Sdk\Shared\Http\Data\ApiRequestDTO;
use Slen\Sdk\Shared\Http\Enums\Endpoint;
use Slen\Sdk\Shared\Tasks\AbstractTask;

/** @internal */
final readonly class GetExecutionStatusTask extends AbstractTask
{
    private const float SHORTEST_READ_SECONDS = 5.0;

    public function __construct(
        ApiTransport $apiTransport,
        private ExecutionStatusResource $executionStatusResource,
    ) {
        parent::__construct($apiTransport);
    }

    /** @throws InvalidRequestException */
    public function request(string $executionId, RequestOptions $options): ApiRequestDTO
    {
        return new ApiRequestDTO(
            Endpoint::GetExecutionStatus,
            ['id' => $executionId],
            retries: $options->retries,
            timeout: $options->timeout,
        );
    }

    /** @throws SlenException */
    public function execute(ApiRequestDTO $request): ExecutionStatusDTO
    {
        return $this->executionStatusResource->map($this->send($request));
    }

    /** @throws SlenException */
    public function read(ApiRequestDTO $request, float $secondsLeft): ExecutionStatusDTO
    {
        $readTimeout = $this->apiTransport->readTimeout;

        if ($readTimeout === null) {
            return $this->execute($request);
        }

        return $this->execute($request->withTimeout(min($readTimeout, max(self::SHORTEST_READ_SECONDS, $secondsLeft))));
    }
}
