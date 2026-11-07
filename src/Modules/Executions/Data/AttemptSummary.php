<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Executions\Data;

/** One attempt of a provider call a node made. */
final readonly class AttemptSummary
{
    /**
     * @param string|null               $cost          a decimal string of money
     * @param int|null                  $inputTokens   the whole input, the cache read and writes included
     * @param array<string, int>|null   $toolCalls     the paid server-tool calls by name
     * @param AttemptCostBreakdown|null $costBreakdown what $cost is made of; null until the attempt is priced
     */
    public function __construct(
        public string $attemptId,
        public int $attemptNumber,
        public string $connectionId,
        public ?string $poolId,
        public string $status,
        public ?string $errorCode,
        public ?int $latencyMs,
        public ?string $cost,
        public ?string $providerRequestId,
        public ?string $modelCode,
        public ?int $inputTokens,
        public ?int $outputTokens,
        public ?int $cachedInputTokens,
        public ?int $cacheWriteTokens,
        public ?int $cacheWriteLongTokens,
        public ?int $reasoningTokens,
        public ?array $toolCalls,
        public ?AttemptCostBreakdown $costBreakdown,
        public ?bool $costIncomplete,
    ) {
    }
}
