<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Operations;

use Slen\Sdk\Exceptions\InvalidRequestException;
use Slen\Sdk\Exceptions\SlenException;
use Slen\Sdk\Modules\Operations\Data\ExecuteRequestDTO;
use Slen\Sdk\Modules\Operations\Data\ExecutionDTO;
use Slen\Sdk\Modules\Operations\Factories\OperationsModuleFactory;
use Slen\Sdk\Modules\Operations\Options\ExecuteOptions;
use Slen\Sdk\Shared\Observability\Data\SpanSubjectDTO;
use Throwable;

/** Runs of flows: the caller names the operation, the server walks the flow bound to it. */
final readonly class OperationsFacade
{
    /** @internal build it with SlenSdk::operations() */
    public function __construct(private OperationsModuleFactory $operationsModuleFactory)
    {
    }

    /**
     * Runs the flow of $operation and answers with the run: finished, or waiting at a queue or an approval.
     *
     * @param array<string, mixed> $input
     *
     * @throws InvalidRequestException nothing sent: an empty customer, credit type or key, a context that names another,
     *                                 a timeout or retries out of range
     * @throws SlenException
     */
    public function execute(string $operation, array $input, ?ExecuteOptions $options = null): ExecutionDTO
    {
        return $this->run('operations.execute', new ExecuteRequestDTO($operation, $input, $options ?? new ExecuteOptions(), false));
    }

    /**
     * The same run handed to a worker: answered at once, `queued`. Follow it with executions()->wait().
     *
     * @param array<string, mixed> $input
     *
     * @throws InvalidRequestException nothing sent: an empty customer, credit type or key, a context that names another,
     *                                 a timeout or retries out of range
     * @throws SlenException
     */
    public function executeAsync(string $operation, array $input, ?ExecuteOptions $options = null): ExecutionDTO
    {
        return $this->run('operations.execute_async', new ExecuteRequestDTO($operation, $input, $options ?? new ExecuteOptions(), true));
    }

    /** @throws SlenException */
    private function run(string $span, ExecuteRequestDTO $executeRequestDTO): ExecutionDTO
    {
        $executeOperationTask = $this->operationsModuleFactory->executeOperationTask();
        $apiRequest = $executeOperationTask->request($executeRequestDTO);
        // The name follows the call, not the answer: a replayed async key may answer a finished run.
        $spanScope = $this->operationsModuleFactory->spanScope(new SpanSubjectDTO($span, $executeRequestDTO->operation));

        try {
            $executionDTO = $executeOperationTask->execute($apiRequest);
        } catch (Throwable $throwable) {
            $spanScope->failed($throwable);

            throw $throwable;
        }

        $spanScope->answered($this->operationsModuleFactory->executionSpanMapper()->map($executionDTO));

        return $executionDTO;
    }
}
