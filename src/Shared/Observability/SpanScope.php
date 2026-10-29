<?php

declare(strict_types=1);

namespace Slen\Sdk\Shared\Observability;

use Slen\Sdk\Exceptions\PolicyDeniedException;
use Slen\Sdk\Exceptions\SlenException;
use Slen\Sdk\Exceptions\SpendRefusedException;
use Slen\Sdk\Observability\SpanDTO;
use Slen\Sdk\Observability\SpanRecorderInterface;
use Slen\Sdk\Shared\Observability\Data\SpanResultDTO;
use Slen\Sdk\Shared\Observability\Data\SpanSubjectDTO;
use Throwable;

/** @internal */
final readonly class SpanScope
{
    private const array REFUSED = ['rejected'];

    private const array BROKEN = ['failed', 'uncertain'];

    private int $startedAt;

    public function __construct(
        private SpanRecorderInterface $spanRecorder,
        private SpanSubjectDTO $spanSubjectDTO,
    ) {
        $this->startedAt = hrtime(true);
    }

    public function answered(SpanResultDTO $spanResultDTO): void
    {
        $outcome = match (true) {
            \in_array($spanResultDTO->status, self::REFUSED, true) => 'refused',
            \in_array($spanResultDTO->status, self::BROKEN, true) => 'error',
            default => 'ok',
        };

        $this->emit(new SpanDTO(
            operation: $this->spanSubjectDTO->operation,
            outcome: $outcome,
            status: $spanResultDTO->status,
            durationMs: $this->elapsedMs(),
            executionId: $spanResultDTO->executionId ?? $this->spanSubjectDTO->executionId,
            operationCode: $spanResultDTO->operationCode ?? $this->spanSubjectDTO->operationCode,
            estimated: $spanResultDTO->estimated,
            actual: $spanResultDTO->actual,
            errorCode: $spanResultDTO->errorCode,
            cacheHit: $spanResultDTO->cacheHit,
        ));
    }

    public function failed(Throwable $throwable): void
    {
        $refused = $throwable instanceof SpendRefusedException || $throwable instanceof PolicyDeniedException;

        $this->emit(new SpanDTO(
            operation: $this->spanSubjectDTO->operation,
            outcome: $refused === true ? 'refused' : 'error',
            status: null,
            durationMs: $this->elapsedMs(),
            executionId: $this->spanSubjectDTO->executionId,
            operationCode: $this->spanSubjectDTO->operationCode,
            errorCode: $throwable instanceof SlenException ? $throwable->errorCode : null,
        ));
    }

    private function emit(SpanDTO $spanDTO): void
    {
        try {
            $this->spanRecorder->record($spanDTO);
        } catch (Throwable) {
            // Telemetry never fails the call it describes, nor replaces the error the caller has to see.
        }
    }

    private function elapsedMs(): int
    {
        return intdiv(hrtime(true) - $this->startedAt, 1_000_000);
    }
}
