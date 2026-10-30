<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Executions\Actions;

use Closure;
use Slen\Sdk\Exceptions\ConnectionException;
use Slen\Sdk\Exceptions\InvalidRequestException;
use Slen\Sdk\Exceptions\SlenException;
use Slen\Sdk\Exceptions\TimeoutException;
use Slen\Sdk\LogMessages;
use Slen\Sdk\Modules\Executions\Data\ExecutionStatusView;
use Slen\Sdk\Modules\Executions\Data\WaitStateDTO;
use Slen\Sdk\Modules\Executions\Options\WaitOptions;
use Slen\Sdk\Modules\Executions\Tasks\GetExecutionStatusTask;
use Slen\Sdk\RequestOptions;
use Slen\Sdk\Shared\Logging\Journal;
use Slen\Sdk\Shared\Timing\TimingInterface;

/** @internal */
final readonly class WaitForExecutionAction
{
    private const float NEGLIGIBLE_SECONDS = 0.001;

    private const float SHORTEST_PAUSE_SECONDS = 0.25;

    private const int LONGEST_BACKOFF_SECONDS = 30;

    public function __construct(
        private GetExecutionStatusTask $getExecutionStatusTask,
        private Journal $journal,
        private TimingInterface $timing,
    ) {
    }

    /** @throws InvalidRequestException */
    public function start(string $executionId, WaitOptions $options): WaitStateDTO
    {
        // Each read is sent once: the wait is the loop that repeats it, and it keeps the deadline.
        return new WaitStateDTO(
            $executionId,
            $this->getExecutionStatusTask->request($executionId, new RequestOptions(retries: 0)),
            $this->timing->monotonicSeconds() + max(1, $options->timeoutSeconds),
            $options->onPollError,
        );
    }

    /** @throws SlenException */
    public function execute(WaitStateDTO $waitStateDTO): ExecutionStatusView
    {
        $seen = null;
        $answers = 0;
        $failures = 0;

        while (true) {
            try {
                $seen = $this->getExecutionStatusTask->read($waitStateDTO->request, $this->secondsLeft($waitStateDTO));
            } catch (SlenException $slenException) {
                $pause = $this->failedRead($slenException, $waitStateDTO, ++$failures);

                if ($pause === null) {
                    return $seen ?? throw $slenException;
                }

                $this->timing->sleep($pause);

                continue;
            }

            $failures = 0;
            $pause = $seen->isPending() === true ? $this->pause($seen->retryAfterSeconds, ++$answers, $waitStateDTO) : null;
            $this->journal->debug(LogMessages::WAIT_POLL, [
                'execution_id' => $waitStateDTO->executionId,
                'status' => $seen->status->value,
                'next_poll_ms' => $pause === null ? null : (int) round($pause * 1_000),
            ]);

            if ($pause === null) {
                return $seen;
            }

            $this->timing->sleep($pause);
        }
    }

    /** @throws SlenException the failure, when waiting does not mend it; whatever the listener throws */
    private function failedRead(SlenException $slenException, WaitStateDTO $waitStateDTO, int $failures): ?float
    {
        if ($this->isPassing($slenException) === false) {
            throw $slenException;
        }

        $this->journal->warning(LogMessages::WAIT_POLL_FAILED, [
            'execution_id' => $waitStateDTO->executionId,
            'failures_in_a_row' => $failures,
            'error_type' => $slenException::type(),
        ]);

        if ($waitStateDTO->onPollError instanceof Closure) {
            ($waitStateDTO->onPollError)($slenException, $failures);
        }

        return $this->pause($slenException->retryAfterSeconds, $failures, $waitStateDTO);
    }

    private function isPassing(SlenException $slenException): bool
    {
        if ($slenException instanceof ConnectionException || $slenException instanceof TimeoutException) {
            return true;
        }

        $status = $slenException->statusCode;

        return $status === 429 || ($status !== null && $status >= 500);
    }

    private function secondsLeft(WaitStateDTO $waitStateDTO): float
    {
        return $waitStateDTO->deadline - $this->timing->monotonicSeconds();
    }

    private function pause(?int $retryAfterSeconds, int $step, WaitStateDTO $waitStateDTO): ?float
    {
        $remaining = $this->secondsLeft($waitStateDTO);

        if ($remaining < self::NEGLIGIBLE_SECONDS) {
            return null;
        }

        $seconds = $retryAfterSeconds ?? min(self::LONGEST_BACKOFF_SECONDS, 2 ** min(5, $step - 1));
        $jittered = $seconds * (1 + $this->timing->random(-25, 25) / 100);

        return min(max(self::SHORTEST_PAUSE_SECONDS, $jittered), $remaining);
    }
}
