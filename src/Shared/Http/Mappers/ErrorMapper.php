<?php

declare(strict_types=1);

namespace Slen\Sdk\Shared\Http\Mappers;

use Slen\Sdk\Exceptions\AuthenticationException;
use Slen\Sdk\Exceptions\BudgetExceededException;
use Slen\Sdk\Exceptions\ConcurrencyLimitException;
use Slen\Sdk\Exceptions\CustomerBlockedException;
use Slen\Sdk\Exceptions\CustomerNotFoundException;
use Slen\Sdk\Exceptions\FlowRuntimeException;
use Slen\Sdk\Exceptions\IdempotencyConflictException;
use Slen\Sdk\Exceptions\InsufficientCreditsException;
use Slen\Sdk\Exceptions\InvalidRequestException;
use Slen\Sdk\Exceptions\OperationLimitExceededException;
use Slen\Sdk\Exceptions\OperationUncertainException;
use Slen\Sdk\Exceptions\OverrunApprovalRequiredException;
use Slen\Sdk\Exceptions\OverrunStoppedException;
use Slen\Sdk\Exceptions\PolicyDeniedException;
use Slen\Sdk\Exceptions\ProviderException;
use Slen\Sdk\Exceptions\RateLimitException;
use Slen\Sdk\Exceptions\ServiceUnavailableException;
use Slen\Sdk\Exceptions\SlenException;
use Slen\Sdk\Exceptions\SpendRefusedException;
use Slen\Sdk\Exceptions\TimeoutException;
use Slen\Sdk\Shared\Http\Data\ApiResponseDTO;

/** @internal */
final readonly class ErrorMapper
{
    /** @var array<string, class-string<SlenException>> */
    private const array BY_CODE = [
        'invalid_api_key' => AuthenticationException::class,
        'insufficient_scope' => AuthenticationException::class,
        'not_authenticated' => AuthenticationException::class,
        'not_a_member' => AuthenticationException::class,
        'account_suspended' => AuthenticationException::class,
        'budget_exceeded' => BudgetExceededException::class,
        'insufficient_credits' => InsufficientCreditsException::class,
        'overrun_stopped' => OverrunStoppedException::class,
        'overrun_approval_required' => OverrunApprovalRequiredException::class,
        'customer_not_found' => CustomerNotFoundException::class,
        'operation_limit_exceeded' => OperationLimitExceededException::class,
        'concurrency_limit_exceeded' => ConcurrencyLimitException::class,
        'rate_limit_exceeded' => RateLimitException::class,
        'policy_denied' => PolicyDeniedException::class,
        'model_not_allowed' => PolicyDeniedException::class,
        'customer_blocked' => CustomerBlockedException::class,
        'idempotency_conflict' => IdempotencyConflictException::class,
        'idempotency_in_progress' => IdempotencyConflictException::class,
        'execution_timeout' => TimeoutException::class,
        'background_hold_expired' => TimeoutException::class,
        'provider_timeout' => ProviderException::class,
        'provider_unavailable' => ProviderException::class,
        'provider_rate_limit' => ProviderException::class,
        'provider_error' => ProviderException::class,
        'provider_not_configured' => ProviderException::class,
        'pool_exhausted' => ProviderException::class,
        'cost_calculation_failed' => InvalidRequestException::class,
        'operation_uncertain' => OperationUncertainException::class,
        'ledger_unavailable' => ServiceUnavailableException::class,
        'service_unavailable' => ServiceUnavailableException::class,
        'flow_runtime_error' => FlowRuntimeException::class,
        'step_limit_exceeded' => FlowRuntimeException::class,
        'unrouted_port' => FlowRuntimeException::class,
        'unknown_node' => FlowRuntimeException::class,
        'node_type_not_supported' => FlowRuntimeException::class,
        'execution_conflict' => FlowRuntimeException::class,
        'http_request_failed' => FlowRuntimeException::class,
    ];

    public function map(ApiResponseDTO $response): SlenException
    {
        $error = \is_array($response->body['error'] ?? null) === true ? $response->body['error'] : [];
        $code = $response->errorCode();
        $class = $this->classFor($code, $response->status);

        return new $class(
            $this->message($error, $response),
            $code,
            $response->status,
            $this->details($error, $response->body),
            $response->requestId(),
            $response->retryAfterSeconds(),
        );
    }

    /** @return class-string<SlenException> */
    public function classFor(?string $code, int $status): string
    {
        if ($code !== null && isset(self::BY_CODE[$code])) {
            return self::BY_CODE[$code];
        }

        // A 5xx nobody named stays without a code: the code is what tells a provider failed.
        return match (true) {
            $status === 401, $status === 403 => AuthenticationException::class,
            $status === 402 => SpendRefusedException::class,
            $status === 429 => RateLimitException::class,
            $status >= 500 => ProviderException::class,
            default => InvalidRequestException::class,
        };
    }

    /** @param array<mixed> $error */
    private function message(array $error, ApiResponseDTO $response): string
    {
        foreach ([$error['message'] ?? null, $response->body['message'] ?? null] as $message) {
            if (\is_string($message) === true && $message !== '') {
                return $message;
            }
        }

        return \sprintf('Slen answered %d.', $response->status);
    }

    /**
     * @param array<mixed> $error
     * @param array<mixed> $body
     *
     * @return array<string, mixed>
     */
    private function details(array $error, array $body): array
    {
        $details = $error['details'] ?? null;

        if (\is_array($details) === false) {
            return \is_array($body['errors'] ?? null) === true ? ['errors' => $body['errors']] : [];
        }

        $keyed = [];

        foreach ($details as $key => $value) {
            $keyed[(string) $key] = $value;
        }

        return $keyed;
    }
}
