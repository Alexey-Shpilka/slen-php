<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit\Modules\Operations;

use Closure;
use DateTimeImmutable;
use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Slen\Sdk\Exceptions\InsufficientCreditsException;
use Slen\Sdk\Exceptions\InvalidRequestException;
use Slen\Sdk\Exceptions\OperationUncertainException;
use Slen\Sdk\Exceptions\ServiceUnavailableException;
use Slen\Sdk\Modules\Operations\Data\ExecutionError;
use Slen\Sdk\Modules\Operations\Data\WaitingOn;
use Slen\Sdk\Modules\Operations\Enums\ExecutionStatus;
use Slen\Sdk\Modules\Operations\OperationsFacade;
use Slen\Sdk\Modules\Operations\Options\ExecuteOptions;
use Slen\Sdk\Observability\SlenSpan;
use Slen\Sdk\Testing\FakeHttpClient;
use Slen\Sdk\Testing\RecordingSpanRecorder;
use Slen\Sdk\Tests\Unit\Support\Answers;
use Slen\Sdk\Tests\Unit\Support\RecordingLogger;

final class OperationsFacadeTest extends TestCase
{
    private const array INPUT = ['messages' => [['role' => 'user', 'content' => 'Where is my order?']]];

    private const string UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    public function testExecutePostsTheOperationAndItsInputUnderAKeyOfItsOwn(): void
    {
        $fakeHttpClient = (new FakeHttpClient())->pushJson(200, ['data' => Answers::execution()]);

        $executionResult = Answers::sdk($fakeHttpClient)->operations()->execute('support.reply', self::INPUT);
        $request = $fakeHttpClient->lastRequest();

        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.slen.ai/api/v1/operations/execute', (string) $request->getUri());
        self::assertSame(['operation' => 'support.reply', 'input' => self::INPUT], self::body($request));
        self::assertMatchesRegularExpression(self::UUID_V4, $request->getHeaderLine('Idempotency-Key'));
        self::assertSame('74438200973594700', $executionResult->executionId);
        self::assertSame(ExecutionStatus::Completed, $executionResult->status);
        self::assertTrue($executionResult->isCompleted());
        self::assertSame(['content' => 'Your order ships today.'], $executionResult->output);
    }

    public function testTheCustomerAndTheCreditTypeTravelInTheContextWithTheCallersOwnValues(): void
    {
        $fakeHttpClient = (new FakeHttpClient())->pushJson(200, ['data' => Answers::execution()]);

        Answers::sdk($fakeHttpClient)->operations()->execute('support.reply', self::INPUT, new ExecuteOptions(
            customer: 'user_8421',
            creditType: 'pro',
            context: ['ticket' => 42, 'user_id' => 'user_8421'],
            idempotencyKey: 'ticket-42:reply',
        ));
        $request = $fakeHttpClient->lastRequest();

        self::assertSame(
            ['operation' => 'support.reply', 'input' => self::INPUT, 'context' => ['ticket' => 42, 'user_id' => 'user_8421', 'credit_type' => 'pro']],
            self::body($request),
        );
        self::assertSame('ticket-42:reply', $request->getHeaderLine('Idempotency-Key'));
    }

    public function testAnEmptyInputIsAnObjectOnTheWireAndAnEmptyContextIsLeftOut(): void
    {
        $fakeHttpClient = (new FakeHttpClient())->pushJson(200, ['data' => Answers::execution()]);

        Answers::sdk($fakeHttpClient)->operations()->execute('support.reply', []);

        self::assertSame('{"operation":"support.reply","input":{}}', (string) $fakeHttpClient->lastRequest()->getBody());
    }

    public function testANumberInTheContextNamesTheSameCustomerAsItsString(): void
    {
        $fakeHttpClient = (new FakeHttpClient())->pushJson(200, ['data' => Answers::execution()]);

        Answers::sdk($fakeHttpClient)->operations()->execute('support.reply', [], new ExecuteOptions(customer: '42', context: ['user_id' => 42]));

        self::assertSame(['user_id' => '42'], self::body($fakeHttpClient->lastRequest())['context']);
    }

    /**
     * @param Closure(OperationsFacade): mixed $call
     */
    #[DataProvider('refusedBeforeTheWireProvider')]
    public function testACallThatCannotBeRightIsRefusedBeforeAnythingIsSent(Closure $call, string $message): void
    {
        $fakeHttpClient = new FakeHttpClient();
        $recordingSpanRecorder = new RecordingSpanRecorder();
        $recordingLogger = new RecordingLogger();

        try {
            $call(Answers::sdk($fakeHttpClient, $recordingSpanRecorder, $recordingLogger)->operations());
            self::fail('the call was not refused');
        } catch (InvalidRequestException $invalidRequestException) {
            self::assertSame('invalid_request', $invalidRequestException->errorCode);
            self::assertStringContainsString($message, $invalidRequestException->getMessage());
        }

        self::assertSame([], $fakeHttpClient->requests());
        self::assertSame([], $recordingSpanRecorder->spans());
        self::assertSame([], $recordingLogger->records);
    }

    /**
     * @return Iterator<string, array{Closure(OperationsFacade): mixed, string}>
     */
    public static function refusedBeforeTheWireProvider(): Iterator
    {
        yield 'two customers' => [
            static function (OperationsFacade $operationsFacade): mixed {
                return $operationsFacade->execute(
                    'a',
                    [],
                    new ExecuteOptions(customer: 'user_1', context: ['user_id' => 'user_2']),
                );
            },
            'customer and context.user_id of POST /v1/operations/execute differ',
        ];
        yield 'two credit types' => [
            static function (OperationsFacade $facade): mixed {
                return $facade->executeAsync(
                    'a',
                    [],
                    new ExecuteOptions(creditType: 'pro', context: ['credit_type' => 'default']),
                );
            },
            'creditType and context.credit_type of POST /v1/operations/execute differ',
        ];
        yield 'an empty customer' => [
            static function (OperationsFacade $operationsFacade): mixed {
                return $operationsFacade->execute('a', [], new ExecuteOptions(customer: ' '));
            },
            'the customer of POST /v1/operations/execute is empty',
        ];
        yield 'an empty credit type' => [
            static function (OperationsFacade $operationsFacade): mixed {
                return $operationsFacade->execute('a', [], new ExecuteOptions(creditType: ''));
            },
            'the creditType of POST /v1/operations/execute is empty',
        ];
        yield 'an empty key' => [
            static function (OperationsFacade $operationsFacade): mixed {
                return $operationsFacade->execute('a', [], new ExecuteOptions(idempotencyKey: ' '));
            },
            'the idempotency key of POST /v1/operations/execute is empty',
        ];

        foreach (['zero' => 0.0, 'negative' => -1.0, 'infinite' => INF, 'not a number' => NAN] as $what => $timeout) {
            yield 'a timeout ' . $what => [
                static function (OperationsFacade $operationsFacade) use ($timeout): mixed {
                    return $operationsFacade->execute('a', [], new ExecuteOptions(timeout: $timeout));
                },
                'the timeout of POST /v1/operations/execute must be a positive number of seconds',
            ];
        }

        yield 'negative retries' => [
            static function (OperationsFacade $operationsFacade): mixed {
                return $operationsFacade->executeAsync('a', [], new ExecuteOptions(retries: -1));
            },
            'the retries of POST /v1/operations/execute must be zero or more, got -1',
        ];
    }

    public function testExecuteAsyncHandsTheRunToAWorkerAndAnswersItQueued(): void
    {
        $fakeHttpClient = (new FakeHttpClient())->pushJson(202, ['data' => Answers::pending('queued', 2, ['replayed' => false, 'error' => null])]);
        $recordingSpanRecorder = new RecordingSpanRecorder();

        $executionResult = Answers::sdk($fakeHttpClient, $recordingSpanRecorder)->operations()->executeAsync('support.reply', self::INPUT);

        self::assertSame('async', self::body($fakeHttpClient->lastRequest())['execution_mode']);
        self::assertTrue($executionResult->isPending());
        self::assertFalse($executionResult->isFinished());
        self::assertSame(2, $executionResult->retryAfterSeconds);
        self::assertEquals(
            new SlenSpan('operations.execute_async', 'ok', 'queued', $recordingSpanRecorder->last()->durationMs, '74438200973594700', 'support.reply'),
            $recordingSpanRecorder->last(),
        );
    }

    public function testTheSpanOfARunCarriesItsCostsAndWhetherItsAnswerWasCached(): void
    {
        $answer = Answers::execution([
            'cost' => ['estimated' => '0.00412500', 'actual' => '0.00390000'],
            'cache' => ['hit' => true, 'cached_at' => '2026-10-17T11:00:00+00:00'],
        ]);
        $fakeHttpClient = (new FakeHttpClient())->pushJson(200, ['data' => $answer]);
        $recordingSpanRecorder = new RecordingSpanRecorder();

        $executionResult = Answers::sdk($fakeHttpClient, $recordingSpanRecorder)->operations()->execute('support.reply', self::INPUT);

        self::assertEquals(new DateTimeImmutable('2026-10-17T11:00:00+00:00'), $executionResult->cache->cachedAt);
        self::assertEquals(new SlenSpan(
            operation: 'operations.execute',
            outcome: 'ok',
            status: 'completed',
            durationMs: $recordingSpanRecorder->last()->durationMs,
            executionId: '74438200973594700',
            operationCode: 'support.reply',
            estimated: '0.00412500',
            actual: '0.00390000',
            cacheHit: true,
        ), $recordingSpanRecorder->last());
    }

    public function testAFlowThatRefusesIsAnAnswerAndItsSpanARefusal(): void
    {
        $answer = Answers::execution([
            'status' => 'rejected',
            'output' => null,
            'error_code' => 'out_of_hours',
            'error_message' => 'We answer between 9 and 18.',
            'error' => ['code' => 'out_of_hours', 'message' => 'We answer between 9 and 18.'],
            'replayed' => true,
        ]);
        $fakeHttpClient = (new FakeHttpClient())->pushJson(200, ['data' => $answer]);
        $recordingSpanRecorder = new RecordingSpanRecorder();

        $executionResult = Answers::sdk($fakeHttpClient, $recordingSpanRecorder)->operations()->execute('support.reply', self::INPUT);

        self::assertTrue($executionResult->isRejected());
        self::assertTrue($executionResult->replayed);
        self::assertEquals(new ExecutionError('out_of_hours', 'We answer between 9 and 18.'), $executionResult->error);
        self::assertSame('refused', $recordingSpanRecorder->last()->outcome);
        self::assertSame('out_of_hours', $recordingSpanRecorder->last()->errorCode);
        self::assertFalse($recordingSpanRecorder->last()->cacheHit);
    }

    public function testARunParkedAtAnApprovalSaysWhereItWaits(): void
    {
        $answer = Answers::pending('waiting', 30, [
            'waiting_on' => ['node_key' => 'approve_refund', 'type' => 'approval', 'resume_at' => null],
            'replayed' => false,
            'error' => null,
        ]);
        $fakeHttpClient = (new FakeHttpClient())->pushJson(202, ['data' => $answer]);

        $executionResult = Answers::sdk($fakeHttpClient)->operations()->execute('support.refund', self::INPUT);

        self::assertEquals(new WaitingOn('approve_refund', 'approval', null), $executionResult->waitingOn);
        self::assertSame(ExecutionStatus::Waiting, $executionResult->status);
    }

    public function testAPlatformRefusalIsThrownAndItsSpanIsARefusalWithTheCode(): void
    {
        $fakeHttpClient = (new FakeHttpClient())->pushJson(402, Answers::refusal('insufficient_credits'));
        $recordingSpanRecorder = new RecordingSpanRecorder();

        try {
            Answers::sdk($fakeHttpClient, $recordingSpanRecorder)->operations()
                ->execute('support.reply', self::INPUT, new ExecuteOptions(customer: 'user_8421'));
            self::fail('the refusal was not thrown');
        } catch (InsufficientCreditsException $insufficientCreditsException) {
            self::assertSame(402, $insufficientCreditsException->statusCode);
        }

        self::assertEquals(
            new SlenSpan(
                'operations.execute',
                'refused',
                null,
                $recordingSpanRecorder->last()->durationMs,
                operationCode: 'support.reply',
                errorCode: 'insufficient_credits',
            ),
            $recordingSpanRecorder->last(),
        );
    }

    public function testAnUncertainOutcomeIsNotRepeatedAndItsSpanIsAnError(): void
    {
        $fakeHttpClient = (new FakeHttpClient())->pushJson(502, Answers::refusal('operation_uncertain'));
        $recordingSpanRecorder = new RecordingSpanRecorder();

        try {
            Answers::sdk($fakeHttpClient, $recordingSpanRecorder, retries: 2)->operations()->execute('support.reply', self::INPUT);
            self::fail('the failure was not thrown');
        } catch (OperationUncertainException) {
            self::assertCount(1, $fakeHttpClient->requests());
        }

        self::assertSame('error', $recordingSpanRecorder->last()->outcome);
    }

    public function testAnUnavailableServerIsAskedAgainUnderTheSameKey(): void
    {
        $fakeHttpClient = (new FakeHttpClient())
            ->pushJson(503, Answers::refusal('service_unavailable'), ['Retry-After' => '0'])
            ->pushJson(200, ['data' => Answers::execution()]);

        Answers::sdk($fakeHttpClient, retries: 1)->operations()->execute('support.reply', self::INPUT, new ExecuteOptions(idempotencyKey: 'order-42:email'));

        self::assertSame(
            ['order-42:email', 'order-42:email'],
            array_map(static function (RequestInterface $request): string {
                return $request->getHeaderLine('Idempotency-Key');
            }, $fakeHttpClient->requests()),
        );
    }

    public function testRetriesNoneSendsACallOnceWhateverTheConfigSays(): void
    {
        $fakeHttpClient = (new FakeHttpClient())->pushJson(503, Answers::refusal('service_unavailable'), ['Retry-After' => '0']);

        try {
            Answers::sdk($fakeHttpClient, retries: 2)->operations()->execute('support.reply', self::INPUT, new ExecuteOptions(retries: 0));
            self::fail('the 503 was not thrown');
        } catch (ServiceUnavailableException) {
            self::assertCount(1, $fakeHttpClient->requests());
        }
    }

    public function testRetriesOfACallReplaceTheOnesOfTheConfig(): void
    {
        $fakeHttpClient = (new FakeHttpClient())
            ->pushJson(503, Answers::refusal('service_unavailable'), ['Retry-After' => '0'])
            ->pushJson(503, Answers::refusal('service_unavailable'), ['Retry-After' => '0'])
            ->pushJson(503, Answers::refusal('service_unavailable'), ['Retry-After' => '0'])
            ->pushJson(503, Answers::refusal('service_unavailable'), ['Retry-After' => '0']);

        try {
            Answers::sdk($fakeHttpClient, retries: 0)->operations()->execute('support.reply', self::INPUT, new ExecuteOptions(retries: 3));
            self::fail('the 503 was not thrown');
        } catch (ServiceUnavailableException) {
            self::assertCount(4, $fakeHttpClient->requests());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function body(RequestInterface $request): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
