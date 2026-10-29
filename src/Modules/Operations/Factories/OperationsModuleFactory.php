<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Operations\Factories;

use Slen\Sdk\Modules\Operations\Resources\ExecutionResource;
use Slen\Sdk\Modules\Operations\Tasks\ExecuteOperationTask;
use Slen\Sdk\Shared\Http\ApiTransport;
use Slen\Sdk\Shared\Observability\Data\SpanSubjectDTO;
use Slen\Sdk\Shared\Observability\ExecutionSpanMapper;
use Slen\Sdk\Shared\Observability\SpanScope;
use Slen\Sdk\Shared\Observability\Telemetry;

/** @internal */
final readonly class OperationsModuleFactory
{
    public function __construct(
        private ApiTransport $apiTransport,
        private Telemetry $telemetry,
    ) {
    }

    public function executeOperationTask(): ExecuteOperationTask
    {
        return new ExecuteOperationTask($this->apiTransport, new ExecutionResource());
    }

    public function spanScope(SpanSubjectDTO $spanSubjectDTO): SpanScope
    {
        return new SpanScope($this->telemetry->spanRecorder, $spanSubjectDTO);
    }

    public function executionSpanMapper(): ExecutionSpanMapper
    {
        return new ExecutionSpanMapper();
    }
}
