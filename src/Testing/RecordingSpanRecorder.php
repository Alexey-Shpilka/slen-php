<?php

declare(strict_types=1);

namespace Slen\Sdk\Testing;

use LogicException;
use Slen\Sdk\Observability\SlenSpan;
use Slen\Sdk\Observability\SpanRecorderInterface;

/** Keeps every span, for a test to assert on. */
final class RecordingSpanRecorder implements SpanRecorderInterface
{
    /** @var list<SlenSpan> */
    private array $spans = [];

    public function record(SlenSpan $span): void
    {
        $this->spans[] = $span;
    }

    /**
     * Every span recorded, in order.
     *
     * @return list<SlenSpan>
     */
    public function spans(): array
    {
        return $this->spans;
    }

    /** @throws LogicException when no span was recorded */
    public function last(): SlenSpan
    {
        return $this->spans[array_key_last($this->spans) ?? throw new LogicException('RecordingSpanRecorder has recorded no span.')];
    }
}
