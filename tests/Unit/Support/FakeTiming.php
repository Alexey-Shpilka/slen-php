<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit\Support;

use Closure;
use Slen\Sdk\Shared\Timing\TimingInterface;

/**
 * Time that moves only when the SDK sleeps, and random numbers a test chooses (the middle of the range unless told).
 */
final class FakeTiming implements TimingInterface
{
    /** @var list<float> seconds, in the order they were slept */
    public array $slept = [];

    /**
     * @param (Closure(int, int): int)|null $random
     */
    public function __construct(
        public float $now = 1_000.0,
        private readonly int $unixSeconds = 1_760_000_000,
        private readonly ?Closure $random = null,
    ) {
    }

    public function monotonicSeconds(): float
    {
        return $this->now;
    }

    public function unixSeconds(): int
    {
        return $this->unixSeconds;
    }

    public function sleep(float $seconds): void
    {
        $this->slept[] = $seconds;
        $this->now += $seconds;
    }

    public function random(int $min, int $max): int
    {
        if ($this->random instanceof Closure) {
            return ($this->random)($min, $max);
        }

        return intdiv($min + $max, 2);
    }

    /**
     * @return list<int>
     */
    public function sleptMilliseconds(): array
    {
        return array_map(static function (float $seconds): int {
            return (int) round($seconds * 1_000);
        }, $this->slept);
    }
}
