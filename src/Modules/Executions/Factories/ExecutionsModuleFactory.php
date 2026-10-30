<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Executions\Factories;

use Slen\Sdk\Modules\Executions\Actions\WaitForExecutionAction;
use Slen\Sdk\Modules\Executions\Resources\ExecutionStatusResource;
use Slen\Sdk\Modules\Executions\Resources\ExecutionTraceResource;
use Slen\Sdk\Modules\Executions\Tasks\GetExecutionStatusTask;
use Slen\Sdk\Modules\Operations\Resources\ExecutionResource;
use Slen\Sdk\Shared\Http\ApiCaller;
use Slen\Sdk\Shared\Http\ApiTransport;
use Slen\Sdk\Shared\Observability\Data\SpanSubjectDTO;
use Slen\Sdk\Shared\Observability\ExecutionSpanMapper;
use Slen\Sdk\Shared\Observability\SpanScope;
use Slen\Sdk\Shared\Observability\Telemetry;
use Slen\Sdk\Shared\Timing\TimingInterface;

/** @internal */
final readonly class ExecutionsModuleFactory
{
    public function __construct(
        private ApiTransport $apiTransport,
        private Telemetry $telemetry,
        private TimingInterface $timing,
    ) {
    }

    public function api(): ApiCaller
    {
        return new ApiCaller($this->apiTransport);
    }

    public function executionTraceResource(): ExecutionTraceResource
    {
        return new ExecutionTraceResource(new ExecutionResource());
    }

    public function getExecutionStatusTask(): GetExecutionStatusTask
    {
        return new GetExecutionStatusTask(
            $this->api(),
            new ExecutionStatusResource(new ExecutionResource()),
            $this->apiTransport->readTimeout,
        );
    }

    public function waitForExecutionAction(): WaitForExecutionAction
    {
        return new WaitForExecutionAction($this->getExecutionStatusTask(), $this->telemetry->journal, $this->timing);
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
