<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit\Shared\Http\Client;

use PHPUnit\Framework\TestCase;
use Slen\Sdk\Exceptions\ConnectionException;
use Slen\Sdk\Exceptions\InvalidResponseException;
use Slen\Sdk\Exceptions\TimeoutException;
use Slen\Sdk\Shared\Http\Client\HttpJournal;
use Slen\Sdk\Shared\Http\Client\RetryClientDecorator;
use Slen\Sdk\Shared\Http\Client\RetryPolicy;
use Slen\Sdk\Shared\Http\Data\ApiRequestDTO;
use Slen\Sdk\Shared\Http\Data\ApiResponseDTO;
use Slen\Sdk\Shared\Http\Enums\Endpoint;
use Slen\Sdk\Shared\Logging\Journal;
use Slen\Sdk\Tests\Unit\Support\FakeTiming;
use Slen\Sdk\Tests\Unit\Support\RecordingLogger;
use Slen\Sdk\Tests\Unit\Support\ScriptedApiClient;

final class RetryClientDecoratorTest extends TestCase
{
    private FakeTiming $fakeTiming;

    private RecordingLogger $recordingLogger;

    protected function setUp(): void
    {
        $this->fakeTiming = new FakeTiming(random: static function (int $min, int $max): int {
            return 1_000;
        });
        $this->recordingLogger = new RecordingLogger();
    }

    public function testSendReturnsAFirstAnswerAtOnce(): void
    {
        $inner = new ScriptedApiClient(new ApiResponseDTO(200));

        $response = $this->decorator($inner)->send($this->request());

        self::assertSame(200, $response->status);
        self::assertSame(1, $response->attempt);
        self::assertCount(1, $inner->requests);
        self::assertSame([], $this->fakeTiming->sleptMilliseconds());
    }

    public function testSendRepeatsARequestThatGotNoAnswerUnderTheSameKey(): void
    {
        $inner = new ScriptedApiClient(new ConnectionException('x'), new TimeoutException('x'), new ApiResponseDTO(201));

        $response = $this->decorator($inner)->send($this->request());

        self::assertSame(201, $response->status);
        self::assertSame(3, $response->attempt);
        self::assertTrue($response->afterNoAnswer);
        self::assertSame([1, 2, 3], array_map(static function (ApiRequestDTO $request): int {
            return $request->attempt;
        }, $inner->requests));
        self::assertSame(['key-1', 'key-1', 'key-1'], array_map(static function (ApiRequestDTO $request): ?string {
            return $request->idempotencyKey;
        }, $inner->requests));
        self::assertSame([200, 400], $this->fakeTiming->sleptMilliseconds());
    }

    public function testSendGivesUpOnNoAnswerAfterEveryAttempt(): void
    {
        $last = new TimeoutException('third');
        $inner = new ScriptedApiClient(new ConnectionException('first'), new ConnectionException('second'), $last);

        try {
            $this->decorator($inner)->send($this->request());
            self::fail('Expected TimeoutException');
        } catch (TimeoutException $timeoutException) {
            self::assertSame($last, $timeoutException);
        }

        self::assertCount(3, $inner->requests);
        self::assertSame([200, 400], $this->fakeTiming->sleptMilliseconds());
    }

    public function testSendWaitsWhatTheServerAskedBeforeRepeatingA503(): void
    {
        $inner = new ScriptedApiClient(new ApiResponseDTO(503, ['retry-after' => '2']), new ApiResponseDTO(200));

        $response = $this->decorator($inner)->send($this->request());

        self::assertSame(200, $response->status);
        self::assertFalse($response->afterNoAnswer);
        self::assertSame([2_000], $this->fakeTiming->sleptMilliseconds());
    }

    public function testSendHandsBackA429ThatAsksForMoreThanTheCeiling(): void
    {
        $inner = new ScriptedApiClient(new ApiResponseDTO(429, ['retry-after' => '60']));

        $response = $this->decorator($inner)->send($this->request());

        self::assertSame(429, $response->status);
        self::assertCount(1, $inner->requests);
        self::assertSame([], $this->fakeTiming->sleptMilliseconds());
    }

    public function testSendHandsBackTheLast503WhenTheAttemptsRunOut(): void
    {
        $inner = new ScriptedApiClient(new ApiResponseDTO(503), new ApiResponseDTO(503), new ApiResponseDTO(503));

        $response = $this->decorator($inner)->send($this->request());

        self::assertSame(503, $response->status);
        self::assertSame(3, $response->attempt);
        self::assertCount(3, $inner->requests);
    }

    public function testSendNeverRepeatsAServerErrorThatMayHaveSpent(): void
    {
        $inner = new ScriptedApiClient(new ApiResponseDTO(502));

        self::assertSame(502, $this->decorator($inner)->send($this->request())->status);
        self::assertCount(1, $inner->requests);
    }

    public function testSendNeverRepeatsAnAnswerItCannotRead(): void
    {
        $inner = new ScriptedApiClient(new InvalidResponseException('x', 'invalid_response', 200), new ApiResponseDTO(200));

        $this->expectException(InvalidResponseException::class);

        try {
            $this->decorator($inner)->send($this->request());
        } finally {
            self::assertCount(1, $inner->requests);
        }
    }

    public function testSendTakesTheRetriesOfTheRequestOverTheConfigured(): void
    {
        $inner = new ScriptedApiClient(new ConnectionException('x'), new ApiResponseDTO(200));

        $this->expectException(ConnectionException::class);

        try {
            $this->decorator($inner)->send(new ApiRequestDTO(Endpoint::GetExecutionStatus, ['id' => 'e1'], retries: 0));
        } finally {
            self::assertCount(1, $inner->requests);
        }
    }

    public function testSendWritesEveryAttemptAndEveryPause(): void
    {
        $inner = new ScriptedApiClient(new ConnectionException('x'), new ApiResponseDTO(429), new ApiResponseDTO(200));

        $this->decorator($inner)->send($this->request());

        self::assertSame(
            ['debug slen.request', 'warning slen.retry', 'debug slen.request', 'warning slen.retry', 'debug slen.request'],
            $this->recordingLogger->events(),
        );
        self::assertSame(
            [[1, 'no_answer', null, 200], [2, 'rate_limited', 429, 400]],
            array_values(array_map(
                static function (array $record): array {
                    return [
                        $record['context']['attempt'],
                        $record['context']['reason'],
                        $record['context']['status'],
                        $record['context']['delay_ms'],
                    ];
                },
                array_filter($this->recordingLogger->records, static function (array $record): bool {
                    return $record['message'] === 'slen.retry';
                }),
            )),
        );
    }

    public function testSendWritesTheFailureOfACallThatGotNoAnswer(): void
    {
        $inner = new ScriptedApiClient(new ConnectionException('x'), new ConnectionException('x'), new TimeoutException('x'));

        try {
            $this->decorator($inner)->send($this->request());
            self::fail('Expected TimeoutException');
        } catch (TimeoutException) {
            self::assertSame('error slen.failed', $this->recordingLogger->events()[5]);
            self::assertSame(
                [
                    'method' => 'POST',
                    'route' => '/v1/operations/execute',
                    'status' => null,
                    'error_code' => null,
                    'error_type' => 'Timeout',
                    'request_id' => null,
                    'attempts' => 3,
                ],
                $this->recordingLogger->context('slen.failed'),
            );
        }
    }

    public function testSendLeavesTheOutcomeOfAnAnswerToTheJournalOfTheCall(): void
    {
        $this->decorator(new ScriptedApiClient(new ApiResponseDTO(402)))->send($this->request());

        self::assertSame(['debug slen.request'], $this->recordingLogger->events());
    }

    public function testSendMarksAnAnswerThatFollowedNoAnswer(): void
    {
        $inner = new ScriptedApiClient(new ConnectionException('x'), new ApiResponseDTO(404));

        $response = $this->decorator($inner)->send($this->request());

        self::assertSame(404, $response->status);
        self::assertTrue($response->afterNoAnswer);
    }

    private function decorator(ScriptedApiClient $inner): RetryClientDecorator
    {
        return new RetryClientDecorator(
            $inner,
            new RetryPolicy(2, $this->fakeTiming),
            new HttpJournal(new Journal($this->recordingLogger)),
        );
    }

    private function request(): ApiRequestDTO
    {
        return new ApiRequestDTO(Endpoint::ExecuteOperation, body: ['operation' => 'a'], idempotencyKey: 'key-1');
    }
}
