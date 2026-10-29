<?php

declare(strict_types=1);

namespace Slen\Sdk\Shared\Http\Client;

use LogicException;
use Slen\Sdk\Exceptions\ConnectionException;
use Slen\Sdk\Exceptions\SlenException;
use Slen\Sdk\Exceptions\TimeoutException;
use Slen\Sdk\Shared\Http\Data\ApiRequestDTO;
use Slen\Sdk\Shared\Http\Data\ApiResponseDTO;
use Slen\Sdk\Shared\Http\Data\AttemptOutcomeDTO;
use Slen\Sdk\Shared\Http\Data\RejectionDTO;
use Slen\Sdk\Shared\Http\Data\RetryDecisionDTO;
use Slen\Sdk\Shared\Http\Enums\RetryReason;

/** @internal */
final readonly class RetryClientDecorator implements ApiClientInterface
{
    public function __construct(
        private ApiClientInterface $apiClient,
        private RetryPolicy $retryPolicy,
        private HttpJournal $httpJournal,
    ) {
    }

    public function send(ApiRequestDTO $request): ApiResponseDTO
    {
        $afterNoAnswer = false;

        for ($attempt = 1;; ++$attempt) {
            $try = $request->withAttempt($attempt);
            $this->httpJournal->request($try);
            $outcome = $this->attempt($try);
            $decision = $this->retryPolicy->decide($attempt, $outcome, $request->retries);

            if ($decision instanceof RetryDecisionDTO) {
                $this->httpJournal->retry($try, $decision, $outcome->response?->status);
                $this->retryPolicy->pause($decision);
                $afterNoAnswer = $afterNoAnswer === true || $decision->reason === RetryReason::NoAnswer;

                continue;
            }

            if ($outcome->noAnswer instanceof SlenException) {
                $this->httpJournal->rejected($try, new RejectionDTO(null, null, null, $outcome->noAnswer::type(), $attempt));

                throw $outcome->noAnswer;
            }

            return $this->answerOf($outcome)->attempted($attempt, $afterNoAnswer);
        }
    }

    private function attempt(ApiRequestDTO $request): AttemptOutcomeDTO
    {
        try {
            return new AttemptOutcomeDTO($this->apiClient->send($request));
        } catch (ConnectionException | TimeoutException $exception) {
            return new AttemptOutcomeDTO(null, $exception);
        }
    }

    /** @throws LogicException */
    private function answerOf(AttemptOutcomeDTO $attemptOutcomeDTO): ApiResponseDTO
    {
        return $attemptOutcomeDTO->response ?? throw new LogicException('Slen: an attempt ended with neither an answer nor its absence.');
    }
}
