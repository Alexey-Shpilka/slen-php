<?php

declare(strict_types=1);

namespace Slen\Sdk\Testing;

use LogicException;
use Slen\Sdk\Observability\SpanDTO;
use Slen\Sdk\Observability\SpanRecorderInterface;

/** Keeps every span, for a test to assert on. */
final class RecordingSpanRecorder implements SpanRecorderInterface
{
    /** @var list<SpanDTO> */
    private array $spans = [];

    public function record(SpanDTO $span): void
    {
        $this->spans[] = $span;
    }

    /**
     * Every span recorded, in order.
     *
     * @return list<SpanDTO>
     */
    public function spans(): array
    {
        return $this->spans;
    }

    /** @throws LogicException when no span was recorded */
    public function last(): SpanDTO
    {
        return $this->spans[array_key_last($this->spans) ?? throw new LogicException('RecordingSpanRecorder has recorded no span.')];
    }
}
