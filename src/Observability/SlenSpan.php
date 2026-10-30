<?php

declare(strict_types=1);

namespace Slen\Sdk\Observability;

/** One call as an APM sees it: ids, money and the verdict, never a prompt or an answer. */
final readonly class SlenSpan
{
    /**
     * @param string      $operation     the call: operations.execute, operations.execute_async or executions.wait
     * @param string      $outcome       ok, refused (a 402, a policy, a rejected run) or error
     * @param string|null $status        the status of the run; null when the call threw
     * @param string|null $estimated     a decimal string of money
     * @param string|null $actual        a decimal string of money; null until the run is captured
     * @param bool|null   $cacheHit      whether a finished run answered from the cache; null until it finished
     */
    public function __construct(
        public string $operation,
        public string $outcome,
        public ?string $status,
        public int $durationMs,
        public ?string $executionId = null,
        public ?string $operationCode = null,
        public ?string $estimated = null,
        public ?string $actual = null,
        public ?string $errorCode = null,
        public ?bool $cacheHit = null,
    ) {
    }

    /**
     * The attributes under their snake_case names; a null is left out, false is a value.
     *
     * @return array<string, string|int|bool>
     */
    public function toArray(): array
    {
        return array_filter([
            'operation' => $this->operation,
            'outcome' => $this->outcome,
            'status' => $this->status,
            'duration_ms' => $this->durationMs,
            'execution_id' => $this->executionId,
            'operation_code' => $this->operationCode,
            'estimated' => $this->estimated,
            'actual' => $this->actual,
            'error_code' => $this->errorCode,
            'cache_hit' => $this->cacheHit,
        ], static function (string|int|bool|null $value): bool {
            return $value !== null;
        });
    }
}
