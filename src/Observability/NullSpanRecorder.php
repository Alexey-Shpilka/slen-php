<?php

declare(strict_types=1);

namespace Slen\Sdk\Observability;

/** The default: spans go nowhere. */
final readonly class NullSpanRecorder implements SpanRecorderInterface
{
    public function record(SpanDTO $span): void
    {
    }
}
