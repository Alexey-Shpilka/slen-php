<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Executions;

use Slen\Sdk\Exceptions\InvalidRequestException;
use Slen\Sdk\Exceptions\SlenException;
use Slen\Sdk\Modules\Executions\Data\ExecutionStatusDTO;
use Slen\Sdk\Modules\Executions\Data\ExecutionTraceDTO;
use Slen\Sdk\Modules\Executions\Factories\ExecutionsModuleFactory;
use Slen\Sdk\Modules\Executions\Options\WaitOptions;
use Slen\Sdk\RequestOptions;
use Slen\Sdk\Shared\Http\Data\ApiRequestDTO;
use Slen\Sdk\Shared\Http\Enums\Endpoint;
use Slen\Sdk\Shared\Observability\Data\SpanSubjectDTO;
use Throwable;

/** Runs that exist: their state, a wait for their end, their trace. */
final readonly class ExecutionsFacade
{
    /** @internal build it with SlenSdk::executions() */
    public function __construct(private ExecutionsModuleFactory $executionsModuleFactory)
    {
    }

    /**
     * The state of a run, read once.
     *
     * @throws InvalidRequestException nothing sent: an empty id, a timeout or retries out of range
     * @throws SlenException
     */
    public function get(string $executionId, ?RequestOptions $options = null): ExecutionStatusDTO
    {
        $getExecutionStatusTask = $this->executionsModuleFactory->getExecutionStatusTask();

        return $getExecutionStatusTask->execute($getExecutionStatusTask->request($executionId, $options ?? new RequestOptions()));
    }

    /**
     * Reads the run until it is over or the time is up, at the pace the server asks for; then the last state read.
     *
     * @throws InvalidRequestException an empty id; nothing sent
     * @throws SlenException            no read succeeded, or one failed for good (a 404, a 401)
     */
    public function wait(string $executionId, ?WaitOptions $options = null): ExecutionStatusDTO
    {
        $waitForExecutionAction = $this->executionsModuleFactory->waitForExecutionAction();
        $waitStateDTO = $waitForExecutionAction->start($executionId, $options ?? new WaitOptions());
        $spanScope = $this->executionsModuleFactory->spanScope(new SpanSubjectDTO('executions.wait', executionId: $executionId));

        try {
            $executionStatusDTO = $waitForExecutionAction->execute($waitStateDTO);
        } catch (Throwable $throwable) {
            $spanScope->failed($throwable);

            throw $throwable;
        }

        $spanScope->answered($this->executionsModuleFactory->executionSpanMapper()->map($executionStatusDTO));

        return $executionStatusDTO;
    }

    /**
     * The trace of a run: its nodes and events, with the cost of each.
     *
     * @throws InvalidRequestException nothing sent: an empty id, a timeout or retries out of range
     * @throws SlenException
     */
    public function trace(string $executionId, ?RequestOptions $options = null): ExecutionTraceDTO
    {
        $options ??= new RequestOptions();
        $apiRequest = new ApiRequestDTO(Endpoint::GetExecutionTrace, ['id' => $executionId], retries: $options->retries, timeout: $options->timeout);

        return $this->executionsModuleFactory->executionTraceResource()->map($this->executionsModuleFactory->api()->data($apiRequest));
    }
}
