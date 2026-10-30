<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Executions\Tasks;

use Slen\Sdk\Exceptions\InvalidRequestException;
use Slen\Sdk\Exceptions\SlenException;
use Slen\Sdk\Modules\Executions\Data\ExecutionStatusView;
use Slen\Sdk\Modules\Executions\Resources\ExecutionStatusResource;
use Slen\Sdk\RequestOptions;
use Slen\Sdk\Shared\Http\ApiCaller;
use Slen\Sdk\Shared\Http\Data\ApiRequestDTO;
use Slen\Sdk\Shared\Http\Enums\Endpoint;

/** @internal */
final readonly class GetExecutionStatusTask
{
    private const float SHORTEST_READ_SECONDS = 5.0;

    public function __construct(
        private ApiCaller $apiCaller,
        private ExecutionStatusResource $executionStatusResource,
        private ?float $readTimeout,
    ) {
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
    public function execute(ApiRequestDTO $request): ExecutionStatusView
    {
        return $this->executionStatusResource->map($this->apiCaller->data($request));
    }

    /** @throws SlenException */
    public function read(ApiRequestDTO $request, float $secondsLeft): ExecutionStatusView
    {
        if ($this->readTimeout === null) {
            return $this->execute($request);
        }

        return $this->execute($request->withTimeout(min($this->readTimeout, max(self::SHORTEST_READ_SECONDS, $secondsLeft))));
    }
}
