<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit\Modules\Executions\Actions;

use ArrayObject;
use Closure;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Slen\Sdk\Exceptions\AuthenticationException;
use Slen\Sdk\Exceptions\ConnectionException;
use Slen\Sdk\Exceptions\RateLimitException;
use Slen\Sdk\Exceptions\ServiceUnavailableException;
use Slen\Sdk\Exceptions\SlenException;
use Slen\Sdk\Modules\Executions\ExecutionsFacade;
use Slen\Sdk\Modules\Executions\Factories\ExecutionsModuleFactory;
use Slen\Sdk\Modules\Executions\Options\WaitOptions;
use Slen\Sdk\Modules\Operations\Enums\ExecutionStatus;
use Slen\Sdk\Observability\SpanDTO;
use Slen\Sdk\Shared\Http\Client\HttpClientFactory;
use Slen\Sdk\Shared\Logging\Journal;
use Slen\Sdk\Shared\Observability\Telemetry;
use Slen\Sdk\Shared\SdkFactory;
use Slen\Sdk\SlenConfig;
use Slen\Sdk\Testing\FakeHttpClient;
use Slen\Sdk\Testing\RecordingSpanRecorder;
use Slen\Sdk\Tests\Unit\Support\Answers;
use Slen\Sdk\Tests\Unit\Support\FakeTiming;
use Slen\Sdk\Tests\Unit\Support\NetworkFailure;
use Slen\Sdk\Tests\Unit\Support\RecordingLogger;

/**
 * The wait on a clock of its own: no test here spends real time.
 */
final class WaitForExecutionActionTest extends TestCase
{
    private const string ID = '74438200973594700';

    private FakeTiming $fakeTiming;

    private RecordingSpanRecorder $recordingSpanRecorder;

    private RecordingLogger $recordingLogger;

    protected function setUp(): void
    {
        $this->fakeTiming = new FakeTiming();
        $this->recordingSpanRecorder = new RecordingSpanRecorder();
        $this->recordingLogger = new RecordingLogger();
    }

    public function testThePausesAreTheOnesTheServerAsksFor(): void
    {
        $fakeHttpClient = (new FakeHttpClient())
            ->pushJson(202, ['data' => Answers::pending('queued', 2)])
            ->pushJson(202, ['data' => Answers::pending('waiting', 5)])
            ->pushJson(200, ['data' => Answers::status()]);

        $executionStatusDTO = $this->action($fakeHttpClient)->wait(self::ID, new WaitOptions(300));

        self::assertTrue($executionStatusDTO->isCompleted());
        self::assertSame([2.0, 5.0], $this->fakeTiming->slept);
        self::assertCount(3, $fakeHttpClient->requests());
    }

    public function testWithoutAPaceFromTheServerThePausesDoubleUpToThirtySeconds(): void
    {
        $fakeHttpClient = new FakeHttpClient();

        for ($read = 0; $read < 7; ++$read) {
            $fakeHttpClient->pushJson(202, ['data' => Answers::pending('running', null)]);
        }

        $this->action($fakeHttpClient->pushJson(200, ['data' => Answers::status()]))->wait(self::ID, new WaitOptions(3_600));

        self::assertSame([1.0, 2.0, 4.0, 8.0, 16.0, 30.0, 30.0], $this->fakeTiming->slept);
    }

    public function testEveryPauseIsMovedByUpToAQuarterEitherWay(): void
    {
        $fakeHttpClient = (new FakeHttpClient())
            ->pushJson(202, ['data' => Answers::pending('queued', 4)])
            ->pushJson(202, ['data' => Answers::pending('queued', 4)])
            ->pushJson(200, ['data' => Answers::status()]);
        $draws = [25, -25];

        $this->action($fakeHttpClient, static function (int $low, int $high) use (&$draws): int {
            self::assertSame([-25, 25], [$low, $high]);

            return (int) array_shift($draws);
        })->wait(self::ID, new WaitOptions(300));

        self::assertSame([5.0, 3.0], $this->fakeTiming->slept);
    }

    public function testWhenTheTimeIsUpTheWaitAnswersTheLastStateItRead(): void
    {
        $fakeHttpClient = new FakeHttpClient();

        for ($read = 0; $read < 4; ++$read) {
            $fakeHttpClient->pushJson(202, ['data' => Answers::pending('queued', 2)]);
        }

        $executionStatusDTO = $this->action($fakeHttpClient)->wait(self::ID, new WaitOptions(5));

        self::assertSame(ExecutionStatus::Queued, $executionStatusDTO->status);
        self::assertSame([2.0, 2.0, 1.0], $this->fakeTiming->slept, 'the last pause is cut to the deadline, and the run is read once more at it');
        self::assertCount(4, $fakeHttpClient->requests());
        self::assertEquals(
            new SpanDTO('executions.wait', 'ok', 'queued', $this->recordingSpanRecorder->last()->durationMs, self::ID, 'support.reply'),
            $this->recordingSpanRecorder->last(),
        );
    }

    public function testTheReadAtTheDeadlineMayFindTheRunOver(): void
    {
        $fakeHttpClient = (new FakeHttpClient())
            ->pushJson(202, ['data' => Answers::pending('waiting', 30)])
            ->pushJson(200, ['data' => Answers::status(['cache' => ['hit' => true, 'cached_at' => '2026-10-17T11:00:00+00:00']])]);

        $executionStatusDTO = $this->action($fakeHttpClient)->wait(self::ID, new WaitOptions(0));

        self::assertTrue($executionStatusDTO->isCompleted());
        self::assertSame([1.0], $this->fakeTiming->slept, 'a timeout below one second is one second');
        self::assertTrue($this->recordingSpanRecorder->last()->cacheHit);
    }

    public function testAReadThatFailsForAPassingReasonIsWaitedOutAndToldToTheListener(): void
    {
        $fakeHttpClient = (new FakeHttpClient())
            ->pushJson(202, ['data' => Answers::pending('queued', 2)])
            ->pushException(new NetworkFailure())
            ->pushJson(503, Answers::refusal('service_unavailable'), ['Retry-After' => '7'])
            ->pushJson(429, Answers::refusal('rate_limit_exceeded'))
            ->pushJson(200, ['data' => Answers::status()]);
        $heard = [];

        $listener = static function (SlenException $slenException, int $inARow) use (&$heard): void {
            $heard[] = [$slenException::class, $inARow];
        };

        $executionStatusDTO = $this->action($fakeHttpClient)->wait(self::ID, new WaitOptions(300, $listener));

        self::assertTrue($executionStatusDTO->isCompleted());
        self::assertSame([[ConnectionException::class, 1], [ServiceUnavailableException::class, 2], [RateLimitException::class, 3]], $heard);
        self::assertSame([2.0, 1.0, 7.0, 4.0], $this->fakeTiming->slept, 'a failure is paused after by its Retry-After, else by the backoff');
        self::assertCount(5, $fakeHttpClient->requests(), 'each read is sent once: the wait is the loop that repeats it');
        self::assertSame(
            ['execution_id' => self::ID, 'failures_in_a_row' => 2, 'error_type' => 'ServiceUnavailable'],
            $this->context('slen.wait.poll_failed', 1),
        );
        self::assertSame(
            ['debug slen.request', 'error slen.failed', 'warning slen.wait.poll_failed'],
            \array_slice($this->recordingLogger->events(), 3, 3),
            'a failed read is written as any request is, and then as a read of the wait',
        );
    }

    public function testAFailureThatWaitingDoesNotMendIsThrownAtOnce(): void
    {
        $fakeHttpClient = (new FakeHttpClient())
            ->pushJson(202, ['data' => Answers::pending('queued', 2)])
            ->pushJson(401, Answers::refusal('invalid_api_key'));
        $heard = 0;

        try {
            $this->action($fakeHttpClient)->wait(self::ID, new WaitOptions(300, static function () use (&$heard): void {
                ++$heard;
            }));
            self::fail('the 401 was not thrown');
        } catch (AuthenticationException $authenticationException) {
            self::assertSame('invalid_api_key', $authenticationException->errorCode);
        }

        self::assertSame(0, $heard);
        self::assertEquals(
            new SpanDTO('executions.wait', 'error', null, $this->recordingSpanRecorder->last()->durationMs, self::ID, errorCode: 'invalid_api_key'),
            $this->recordingSpanRecorder->last(),
        );
    }

    public function testARunThatWasNeverReadEndsTheWaitWithTheLastFailure(): void
    {
        $fakeHttpClient = (new FakeHttpClient())
            ->pushException(new NetworkFailure())
            ->pushException(new NetworkFailure())
            ->pushException(new NetworkFailure('Connection reset'));

        try {
            $this->action($fakeHttpClient)->wait(self::ID, new WaitOptions(3));
            self::fail('the wait answered without a state');
        } catch (ConnectionException $connectionException) {
            self::assertInstanceOf(NetworkFailure::class, $connectionException->getPrevious());
            self::assertSame('Connection reset', $connectionException->getPrevious()->getMessage());
        }

        self::assertSame([1.0, 2.0], $this->fakeTiming->slept);
        self::assertSame('error', $this->recordingSpanRecorder->last()->outcome);
    }

    public function testAStateReadBeforeTheFailuresIsTheAnswerWhenTheTimeIsUp(): void
    {
        $fakeHttpClient = (new FakeHttpClient())
            ->pushJson(202, ['data' => Answers::pending('waiting', 1)])
            ->pushJson(502, Answers::refusal('provider_error'))
            ->pushJson(500, [])
            ->pushException(new NetworkFailure());

        $executionStatusDTO = $this->action($fakeHttpClient)->wait(self::ID, new WaitOptions(3));

        self::assertSame(ExecutionStatus::Waiting, $executionStatusDTO->status);
        self::assertSame([1.0, 1.0, 1.0], $this->fakeTiming->slept);
    }

    public function testWhatTheListenerThrowsEndsTheWait(): void
    {
        $fakeHttpClient = (new FakeHttpClient())->pushException(new NetworkFailure());

        try {
            $this->action($fakeHttpClient)->wait(self::ID, new WaitOptions(300, static function (): never {
                throw new RuntimeException('enough');
            }));
            self::fail('the listener did not end the wait');
        } catch (RuntimeException $runtimeException) {
            self::assertSame('enough', $runtimeException->getMessage());
        }

        self::assertSame([], $this->fakeTiming->slept);
        self::assertSame('error', $this->recordingSpanRecorder->last()->outcome);
    }

    public function testOnAClientThatCannotTakeATimeoutTheReadsAskForNoneAndWarnOfNothing(): void
    {
        $fakeHttpClient = (new FakeHttpClient())
            ->pushJson(202, ['data' => Answers::pending('queued', 2)])
            ->pushJson(202, ['data' => Answers::pending('running', 2)])
            ->pushJson(200, ['data' => Answers::status()]);

        $this->action($fakeHttpClient)->wait(self::ID, new WaitOptions(300));

        self::assertCount(3, $fakeHttpClient->requests());
        self::assertNotContains('warning slen.config.timeout_not_applied', $this->recordingLogger->events());
    }

    public function testEveryReadOfAWaitIsInTheJournalWithThePauseThatFollows(): void
    {
        $fakeHttpClient = (new FakeHttpClient())
            ->pushJson(202, ['data' => Answers::pending('queued', 2)])
            ->pushJson(200, ['data' => Answers::status()]);

        $this->action($fakeHttpClient)->wait(self::ID, new WaitOptions(300));

        self::assertSame(['execution_id' => self::ID, 'status' => 'queued', 'next_poll_ms' => 2_000], $this->context('slen.wait.poll', 0));
        self::assertSame(['execution_id' => self::ID, 'status' => 'completed', 'next_poll_ms' => null], $this->context('slen.wait.poll', 1));
        self::assertStringNotContainsString(Answers::KEY, json_encode($this->recordingLogger->records, JSON_THROW_ON_ERROR));
    }

    public function testEachReadLastsNoLongerThanTheWaitHasLeftButAtLeastFiveSeconds(): void
    {
        /** @var ArrayObject<int, array{request: RequestInterface, response: ResponseInterface|null, error: mixed, options: array<mixed>}> $history */
        $history = new ArrayObject();
        $container = $history;
        $handlerStack = HandlerStack::create(new MockHandler([
            new Response(202, ['Content-Type' => 'application/json'], json_encode(['data' => Answers::pending('queued', 10)], JSON_THROW_ON_ERROR)),
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['data' => Answers::status()], JSON_THROW_ON_ERROR)),
        ]));
        $handlerStack->push(Middleware::history($container));

        $guzzleClient = new GuzzleClient(['handler' => $handlerStack]);
        $slenConfig = new SlenConfig(Answers::KEY, timeout: 65.0, retries: 2, httpClient: $guzzleClient);

        $this->facadeOf($slenConfig)->wait(self::ID, new WaitOptions(12));

        self::assertSame([10.0], $this->fakeTiming->slept);
        self::assertSame([12.0, 5.0], self::timeouts($history));
    }

    public function testAReadOfALongWaitTakesNoLongerThanTheTimeoutOfTheConfig(): void
    {
        /** @var ArrayObject<int, array{request: RequestInterface, response: ResponseInterface|null, error: mixed, options: array<mixed>}> $history */
        $history = new ArrayObject();
        $container = $history;
        $handlerStack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['data' => Answers::status()], JSON_THROW_ON_ERROR)),
        ]));
        $handlerStack->push(Middleware::history($container));

        $guzzleClient = new GuzzleClient(['handler' => $handlerStack]);
        $slenConfig = new SlenConfig(Answers::KEY, timeout: 65.0, httpClient: $guzzleClient);

        $this->facadeOf($slenConfig)->wait(self::ID, new WaitOptions(300));

        self::assertSame([65.0], self::timeouts($history));
    }

    /**
     * @param ArrayObject<int, array{request: RequestInterface, response: ResponseInterface|null, error: mixed, options: array<mixed>}> $history
     *
     * @return list<mixed> the timeout each request of the history was sent with
     */
    private static function timeouts(ArrayObject $history): array
    {
        $timeouts = [];

        foreach ($history as $sent) {
            $timeouts[] = $sent['options']['timeout'] ?? null;
        }

        return $timeouts;
    }

    /**
     * The wait of a facade on fake time, its random draws the middle of the range unless $random says.
     *
     * @param (Closure(int, int): int)|null $random
     */
    private function action(FakeHttpClient $fakeHttpClient, ?Closure $random = null): ExecutionsFacade
    {
        $this->fakeTiming = new FakeTiming(random: $random);

        // Retries in the config: the wait has to send its reads once all the same.
        return $this->facadeOf(Answers::config($fakeHttpClient, $this->recordingSpanRecorder, $this->recordingLogger, retries: 2));
    }

    private function facadeOf(SlenConfig $slenConfig): ExecutionsFacade
    {
        $journal = new Journal($slenConfig->logger);
        $sdkFactory = new SdkFactory((new HttpClientFactory())->create($slenConfig, $journal), $this->fakeTiming);
        $telemetry = new Telemetry($journal, $slenConfig->spanRecorder);

        return new ExecutionsFacade(new ExecutionsModuleFactory($sdkFactory->apiTransport($slenConfig, $journal), $telemetry, $this->fakeTiming));
    }

    /**
     * The context of the $nth record of $message.
     *
     * @return array<mixed>
     */
    private function context(string $message, int $nth): array
    {
        $records = array_values(array_filter($this->recordingLogger->records, static function (array $record) use ($message): bool {
            return $record['message'] === $message;
        }));

        return ($records[$nth] ?? throw new RuntimeException('no record ' . $nth . ' of ' . $message))['context'];
    }
}
