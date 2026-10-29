<?php

declare(strict_types=1);

namespace Slen\Sdk\Shared\Observability;

use Slen\Sdk\Observability\SpanRecorderInterface;
use Slen\Sdk\Shared\Logging\Journal;

/** @internal */
final readonly class Telemetry
{
    public function __construct(
        public Journal $journal,
        public SpanRecorderInterface $spanRecorder,
    ) {
    }
}
