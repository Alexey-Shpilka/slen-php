<?php

declare(strict_types=1);

namespace Slen\Sdk\Shared\Http\Client;

use Slen\Sdk\Exceptions\ConnectionException;
use Slen\Sdk\Exceptions\TimeoutException;
use Slen\Sdk\Shared\Http\Data\ApiResponseDTO;
use Slen\Sdk\Shared\Http\Data\AttemptOutcomeDTO;
use Slen\Sdk\Shared\Http\Data\RetryDecisionDTO;
use Slen\Sdk\Shared\Http\Enums\RetryReason;
use Slen\Sdk\Shared\Timing\TimingInterface;

/** @internal */
final readonly class RetryPolicy
{
    public const int RETRY_AFTER_CEILING_SECONDS = 30;

    private const int BACKOFF_BASE_MS = 200;

    private const int BACKOFF_CEILING_MS = 5_000;

    public function __construct(
        private int $retries,
        private TimingInterface $timing,
    ) {
    }

    public function decide(int $attempt, AttemptOutcomeDTO $attemptOutcomeDTO, ?int $retries = null): ?RetryDecisionDTO
    {
        if ($attempt > ($retries ?? $this->retries)) {
            return null;
        }

        $noAnswer = $attemptOutcomeDTO->noAnswer;

        if ($noAnswer instanceof ConnectionException || $noAnswer instanceof TimeoutException) {
            return new RetryDecisionDTO(RetryReason::NoAnswer, $this->backoffMs($attempt));
        }

        $response = $attemptOutcomeDTO->response;

        if ($response instanceof ApiResponseDTO === false) {
            return null;
        }

        $reason = match ($response->status) {
            429 => RetryReason::RateLimited,
            503 => RetryReason::Unavailable,
            default => null,
        };

        if ($reason === null) {
            return null;
        }

        $after = $response->retryAfterSeconds();

        if ($after === null) {
            return new RetryDecisionDTO($reason, $this->backoffMs($attempt));
        }

        // Past the ceiling the caller decides: a worker that sleeps that long looks hung.
        return $after <= self::RETRY_AFTER_CEILING_SECONDS ? new RetryDecisionDTO($reason, $after * 1_000) : null;
    }

    public function pause(RetryDecisionDTO $retryDecision): void
    {
        $this->timing->sleep($retryDecision->delayMs / 1_000);
    }

    private function backoffMs(int $attempt): int
    {
        $base = self::BACKOFF_BASE_MS * 2 ** min($attempt - 1, 16);

        return min(intdiv($base * $this->timing->random(750, 1_250), 1_000), self::BACKOFF_CEILING_MS);
    }
}
