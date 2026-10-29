<?php

declare(strict_types=1);

namespace Slen\Sdk\Shared\Timing;

/** @internal */
interface TimingInterface
{
    public function monotonicSeconds(): float;

    public function unixSeconds(): int;

    public function sleep(float $seconds): void;

    public function random(int $min, int $max): int;
}
