<?php

declare(strict_types=1);

namespace Slen\Sdk\Observability;

/** Where finished spans go; it runs on the path of every run, so it should neither throw nor block. */
interface SpanRecorderInterface
{
    public function record(SlenSpan $span): void;
}
