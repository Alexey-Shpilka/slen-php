<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit\Shared\Http\Client;

use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slen\Sdk\Exceptions\ConnectionException;
use Slen\Sdk\Exceptions\InvalidResponseException;
use Slen\Sdk\Exceptions\SlenException;
use Slen\Sdk\Exceptions\TimeoutException;
use Slen\Sdk\Shared\Http\Client\RetryPolicy;
use Slen\Sdk\Shared\Http\Data\ApiResponseDTO;
use Slen\Sdk\Shared\Http\Data\AttemptOutcomeDTO;
use Slen\Sdk\Shared\Http\Data\RetryDecisionDTO;
use Slen\Sdk\Shared\Http\Enums\RetryReason;
use Slen\Sdk\Shared\Timing\SystemTiming;
use Slen\Sdk\Tests\Unit\Support\FakeTiming;

final class RetryPolicyTest extends TestCase
{
    #[DataProvider('outcomeProvider')]
    public function testDecideRepeatsNoAnswer429And503Only(ApiResponseDTO|SlenException $outcome, ?RetryReason $reason, ?int $delayMs): void
    {
        $decision = $this->policy(retries: 2)->decide(1, self::outcome($outcome));

        self::assertSame($reason, $decision?->reason);
        self::assertSame($delayMs, $decision?->delayMs);
    }

    /**
     * @return Iterator<string, array{ApiResponseDTO|SlenException, ?RetryReason, ?int}>
     */
    public static function outcomeProvider(): Iterator
    {
        yield 'connection failed' => [new ConnectionException('x'), RetryReason::NoAnswer, 200];
        yield 'timed out' => [new TimeoutException('x'), RetryReason::NoAnswer, 200];
        yield '429 without Retry-After' => [new ApiResponseDTO(429), RetryReason::RateLimited, 200];
        yield '429 with Retry-After 3' => [new ApiResponseDTO(429, ['retry-after' => '3']), RetryReason::RateLimited, 3_000];
        yield '429 with Retry-After 30' => [new ApiResponseDTO(429, ['retry-after' => '30']), RetryReason::RateLimited, 30_000];
        yield '429 with Retry-After 31' => [new ApiResponseDTO(429, ['retry-after' => '31']), null, null];
        yield '503 without Retry-After' => [new ApiResponseDTO(503), RetryReason::Unavailable, 200];
        yield '503 with Retry-After 0' => [new ApiResponseDTO(503, ['retry-after' => '0']), RetryReason::Unavailable, 0];
        yield '503 with Retry-After 120' => [new ApiResponseDTO(503, ['retry-after' => '120']), null, null];
        yield '500' => [new ApiResponseDTO(500), null, null];
        yield '502' => [new ApiResponseDTO(502), null, null];
        yield '504' => [new ApiResponseDTO(504), null, null];
        yield '402' => [new ApiResponseDTO(402), null, null];
        yield '409' => [new ApiResponseDTO(409), null, null];
        yield '200' => [new ApiResponseDTO(200), null, null];
        yield 'an answer that cannot be read' => [new InvalidResponseException('x', 'invalid_response', 200), null, null];
    }

    public function testDecideStopsAfterTheRetriesOfTheConfig(): void
    {
        $policy = $this->policy(retries: 2);

        self::assertInstanceOf(RetryDecisionDTO::class, $policy->decide(2, self::outcome(new ConnectionException('x'))));
        self::assertNotInstanceOf(RetryDecisionDTO::class, $policy->decide(3, self::outcome(new ConnectionException('x'))));
        self::assertNotInstanceOf(RetryDecisionDTO::class, $this->policy(retries: 0)->decide(1, self::outcome(new ApiResponseDTO(503))));
        self::assertInstanceOf(RetryDecisionDTO::class, $this->policy(retries: 0)->decide(1, self::outcome(new ApiResponseDTO(503)), 1));
        self::assertNotInstanceOf(RetryDecisionDTO::class, $policy->decide(1, self::outcome(new ApiResponseDTO(503)), 0));
    }

    public function testTheBackoffDoublesAndStopsAtFiveSeconds(): void
    {
        $policy = $this->policy(retries: 10);
        $delays = [];

        foreach (range(1, 7) as $attempt) {
            $delays[] = $policy->decide($attempt, self::outcome(new ConnectionException('x')))?->delayMs;
        }

        self::assertSame([200, 400, 800, 1_600, 3_200, 5_000, 5_000], $delays);
    }

    public function testTheBackoffVariesByAQuarterEitherWayWithinTheCeiling(): void
    {
        $low = new RetryPolicy(10, new FakeTiming(random: static function (int $min, int $max): int {
            return $min;
        }));
        $high = new RetryPolicy(10, new FakeTiming(random: static function (int $min, int $max): int {
            return $max;
        }));

        self::assertSame(150, $low->decide(1, self::outcome(new ConnectionException('x')))?->delayMs);
        self::assertSame(250, $high->decide(1, self::outcome(new ConnectionException('x')))?->delayMs);
        self::assertSame(2_400, $low->decide(5, self::outcome(new ConnectionException('x')))?->delayMs);
        self::assertSame(4_000, $high->decide(5, self::outcome(new ConnectionException('x')))?->delayMs);
        self::assertSame(4_800, $low->decide(6, self::outcome(new ConnectionException('x')))?->delayMs);
        self::assertSame(5_000, $high->decide(6, self::outcome(new ConnectionException('x')))?->delayMs);
    }

    public function testTheDefaultBackoffStaysWithinItsBounds(): void
    {
        $delay = (new RetryPolicy(2, new SystemTiming()))->decide(1, self::outcome(new ConnectionException('x')))?->delayMs;

        self::assertNotNull($delay);
        self::assertGreaterThanOrEqual(150, $delay);
        self::assertLessThanOrEqual(250, $delay);
    }

    private function policy(int $retries): RetryPolicy
    {
        return new RetryPolicy($retries, new FakeTiming(random: static function (int $min, int $max): int {
            return 1_000;
        }));
    }

    private static function outcome(ApiResponseDTO|SlenException $outcome): AttemptOutcomeDTO
    {
        return $outcome instanceof ApiResponseDTO ? new AttemptOutcomeDTO($outcome) : new AttemptOutcomeDTO(null, $outcome);
    }
}
