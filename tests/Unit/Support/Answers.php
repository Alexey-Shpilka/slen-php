<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit\Support;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Slen\Sdk\Observability\NullSpanRecorder;
use Slen\Sdk\Observability\SpanRecorderInterface;
use Slen\Sdk\SlenConfig;
use Slen\Sdk\SlenSdk;
use Slen\Sdk\Testing\FakeHttpClient;

/**
 * The answers of the API about runs, whole, and an SDK that sends to a FakeHttpClient.
 */
final class Answers
{
    public const string KEY = 'sk_test_offline';

    public static function sdk(
        FakeHttpClient $fakeHttpClient,
        SpanRecorderInterface $spanRecorder = new NullSpanRecorder(),
        LoggerInterface $logger = new NullLogger(),
        int $retries = 0,
    ): SlenSdk {
        return SlenSdk::create(self::config($fakeHttpClient, $spanRecorder, $logger, $retries));
    }

    public static function config(
        FakeHttpClient $fakeHttpClient,
        SpanRecorderInterface $spanRecorder = new NullSpanRecorder(),
        LoggerInterface $logger = new NullLogger(),
        int $retries = 0,
    ): SlenConfig {
        $psr17Factory = new Psr17Factory();
        $slenConfig = new SlenConfig(self::KEY, retries: $retries, logger: $logger, spanRecorder: $spanRecorder);

        return $slenConfig->withHttpClient($fakeHttpClient, $psr17Factory, $psr17Factory);
    }

    /**
     * `ExecutionStatusView`, with $overrides on top.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function status(array $overrides = []): array
    {
        return [
            'execution_id' => '74438200973594700',
            'status' => 'completed',
            'operation' => 'support.reply',
            'output' => ['content' => 'Your order ships today.'],
            'error_code' => null,
            'error_message' => null,
            'cost' => ['estimated' => '0.00412500', 'actual' => null],
            'cache' => ['hit' => false, 'cached_at' => null],
            'waiting_on' => null,
            'trace_url' => '/v1/executions/74438200973594700',
            ...$overrides,
        ];
    }

    /**
     * `ExecutionResult`: the status view and `error`, `replayed`.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function execution(array $overrides = []): array
    {
        return self::status(['error' => null, 'replayed' => false, ...$overrides]);
    }

    /**
     * A run that has not finished: `queued`, with the pace the server asks for.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function pending(string $status = 'queued', ?int $retryAfterSeconds = 2, array $overrides = []): array
    {
        $pending = ['status' => $status, 'output' => null, 'cost' => ['estimated' => null, 'actual' => null], ...$overrides];

        if ($retryAfterSeconds !== null) {
            $pending['retry_after_seconds'] = $retryAfterSeconds;
        }

        return self::status($pending);
    }

    /**
     * A refusal in the error envelope.
     *
     * @return array<string, mixed>
     */
    public static function refusal(string $code, string $message = 'Refused.'): array
    {
        return ['error' => ['code' => $code, 'message' => $message, 'request_id' => 'req_1']];
    }
}
