<?php

declare(strict_types=1);

namespace Slen\Sdk\Shared\Http\Client;

use Slen\Sdk\LogMessages;
use Slen\Sdk\Shared\Http\Data\ApiRequestDTO;
use Slen\Sdk\Shared\Http\Data\ApiResponseDTO;
use Slen\Sdk\Shared\Http\Data\RejectionDTO;
use Slen\Sdk\Shared\Http\Data\RetryDecisionDTO;
use Slen\Sdk\Shared\Logging\Journal;

/** @internal */
final readonly class HttpJournal
{
    private const array KEY_CODES = ['insufficient_scope', 'invalid_api_key', 'not_a_member'];

    public function __construct(private Journal $journal)
    {
    }

    public function request(ApiRequestDTO $request): void
    {
        $this->journal->debug(LogMessages::REQUEST, [...$this->where($request), 'attempt' => $request->attempt]);
    }

    public function response(ApiRequestDTO $request, ApiResponseDTO $response, int $durationMs): void
    {
        $this->journal->debug(LogMessages::RESPONSE, [
            ...$this->where($request),
            'status' => $response->status,
            'duration_ms' => $durationMs,
            'request_id' => $response->requestId(),
        ]);
    }

    public function retry(ApiRequestDTO $request, RetryDecisionDTO $retryDecision, ?int $status): void
    {
        $this->journal->warning(LogMessages::RETRY, [
            ...$this->where($request),
            'attempt' => $request->attempt,
            'delay_ms' => $retryDecision->delayMs,
            'reason' => $retryDecision->reason->value,
            'status' => $status,
        ]);
    }

    public function timeoutNotApplied(ApiRequestDTO $request, string $client): void
    {
        $this->journal->warning(LogMessages::CONFIG_TIMEOUT_NOT_APPLIED, [...$this->where($request), 'client' => $client]);
    }

    public function rejected(ApiRequestDTO $request, RejectionDTO $rejectionDTO): void
    {
        $fields = [...$this->where($request), 'status' => $rejectionDTO->status, 'error_code' => $rejectionDTO->errorCode];

        if ($this->isAboutTheKey($rejectionDTO) === true) {
            $this->journal->error(LogMessages::UNAUTHORIZED, [...$fields, 'request_id' => $rejectionDTO->requestId]);

            return;
        }

        if ($rejectionDTO->status !== null && $rejectionDTO->status >= 400 && $rejectionDTO->status < 500) {
            $this->journal->info(LogMessages::REFUSED, [...$fields, 'request_id' => $rejectionDTO->requestId]);

            return;
        }

        $this->journal->error(LogMessages::FAILED, [
            ...$fields,
            'error_type' => $rejectionDTO->errorType,
            'request_id' => $rejectionDTO->requestId,
            'attempts' => $rejectionDTO->attempts,
        ]);
    }

    private function isAboutTheKey(RejectionDTO $rejectionDTO): bool
    {
        $errorCode = $rejectionDTO->errorCode;

        return $rejectionDTO->status === 401
            || ($rejectionDTO->status === 403 && ($errorCode === null || \in_array($errorCode, self::KEY_CODES, true)));
    }

    /** @return array{method: string, route: string} */
    private function where(ApiRequestDTO $request): array
    {
        return ['method' => $request->endpoint->method()->value, 'route' => $request->endpoint->path()];
    }
}
