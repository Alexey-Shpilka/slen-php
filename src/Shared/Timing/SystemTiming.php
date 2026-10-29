<?php

declare(strict_types=1);

namespace Slen\Sdk\Shared\Timing;

/** @internal */
final readonly class SystemTiming implements TimingInterface
{
    public function monotonicSeconds(): float
    {
        return hrtime(true) / 1_000_000_000;
    }

    public function unixSeconds(): int
    {
        return time();
    }

    public function sleep(float $seconds): void
    {
        $whole = (int) $seconds;
        time_nanosleep($whole, (int) (($seconds - $whole) * 1_000_000_000));
    }

    public function random(int $min, int $max): int
    {
        return random_int($min, $max);
    }
}
