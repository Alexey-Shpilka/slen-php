<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Executions\Tasks;

use Slen\Sdk\Exceptions\SlenException;
use Slen\Sdk\Modules\Executions\Data\ExecutionTraceDTO;
use Slen\Sdk\Modules\Executions\Resources\ExecutionTraceResource;
use Slen\Sdk\RequestOptions;
use Slen\Sdk\Shared\Http\ApiTransport;
use Slen\Sdk\Shared\Http\Data\ApiRequestDTO;
use Slen\Sdk\Shared\Http\Enums\Endpoint;
use Slen\Sdk\Shared\Tasks\AbstractTask;

/** @internal */
final readonly class GetExecutionTraceTask extends AbstractTask
{
    public function __construct(
        ApiTransport $apiTransport,
        private ExecutionTraceResource $executionTraceResource,
    ) {
        parent::__construct($apiTransport);
    }

    /** @throws SlenException */
    public function execute(string $executionId, RequestOptions $options): ExecutionTraceDTO
    {
        $apiRequest = new ApiRequestDTO(
            Endpoint::GetExecutionTrace,
            ['id' => $executionId],
            retries: $options->retries,
            timeout: $options->timeout,
        );

        return $this->executionTraceResource->map($this->send($apiRequest));
    }
}
