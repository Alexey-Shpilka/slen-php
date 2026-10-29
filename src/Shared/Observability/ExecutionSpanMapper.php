<?php

declare(strict_types=1);

namespace Slen\Sdk\Shared\Observability;

use Slen\Sdk\Modules\Executions\Data\ExecutionStatusDTO;
use Slen\Sdk\Modules\Operations\Data\ExecutionDTO;
use Slen\Sdk\Shared\Observability\Data\SpanResultDTO;

/** @internal */
final readonly class ExecutionSpanMapper
{
    public function map(ExecutionDTO|ExecutionStatusDTO $run): SpanResultDTO
    {
        return new SpanResultDTO(
            status: $run->status->value,
            executionId: $run->executionId,
            operationCode: $run->operationCode,
            estimated: $run->cost->estimated,
            actual: $run->cost->actual,
            errorCode: $run->errorCode,
            cacheHit: $run->isFinished() === true ? $run->cache->hit : null,
        );
    }
}
