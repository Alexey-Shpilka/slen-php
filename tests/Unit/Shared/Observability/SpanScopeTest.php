<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit\Shared\Observability;

use Iterator;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Slen\Sdk\Exceptions\AuthenticationException;
use Slen\Sdk\Exceptions\BudgetExceededException;
use Slen\Sdk\Exceptions\ConnectionException;
use Slen\Sdk\Exceptions\InsufficientCreditsException;
use Slen\Sdk\Exceptions\PolicyDeniedException;
use Slen\Sdk\Exceptions\ProviderException;
use Slen\Sdk\Exceptions\RateLimitException;
use Slen\Sdk\Exceptions\SpendRefusedException;
use Slen\Sdk\Observability\SpanDTO;
use Slen\Sdk\Observability\SpanRecorderInterface;
use Slen\Sdk\Shared\Observability\Data\SpanResultDTO;
use Slen\Sdk\Shared\Observability\Data\SpanSubjectDTO;
use Slen\Sdk\Shared\Observability\SpanScope;
use Slen\Sdk\Testing\RecordingSpanRecorder;
use Throwable;

final class SpanScopeTest extends TestCase
{
    #[DataProvider('statusProvider')]
    public function testTheOutcomeOfAnAnswerFollowsTheStatusOfTheRun(string $status, string $outcome): void
    {
        $recorder = new RecordingSpanRecorder();

        (new SpanScope($recorder, new SpanSubjectDTO('operations.execute', operationCode: 'support.reply')))
            ->answered(new SpanResultDTO($status, '7', 'support.reply', '0.01000000', null, null, null));

        self::assertSame($outcome, $recorder->last()->outcome);
        self::assertSame($status, $recorder->last()->status);
    }

    /**
     * @return Iterator<string, array{string, string}>
     */
    public static function statusProvider(): Iterator
    {
        yield 'completed' => ['completed', 'ok'];
        yield 'waiting' => ['waiting', 'ok'];
        yield 'queued' => ['queued', 'ok'];
        yield 'running' => ['running', 'ok'];
        yield 'rejected' => ['rejected', 'refused'];
        yield 'failed' => ['failed', 'error'];
        yield 'uncertain' => ['uncertain', 'error'];
    }

    public function testAnAnswerCarriesWhatTheRunSays(): void
    {
        $recorder = new RecordingSpanRecorder();

        (new SpanScope($recorder, new SpanSubjectDTO('operations.execute', operationCode: 'support.reply')))
            ->answered(new SpanResultDTO('rejected', '74438200973594700', 'support.reply', '0.00412500', '0.00100000', 'budget_exceeded', false));

        self::assertEquals(new SpanDTO(
            operation: 'operations.execute',
            outcome: 'refused',
            status: 'rejected',
            durationMs: $recorder->last()->durationMs,
            executionId: '74438200973594700',
            operationCode: 'support.reply',
            estimated: '0.00412500',
            actual: '0.00100000',
            errorCode: 'budget_exceeded',
            cacheHit: false,
        ), $recorder->last());
        self::assertGreaterThanOrEqual(0, $recorder->last()->durationMs);
    }

    public function testWhatWasKnownBeforeTheCallFillsWhatTheAnswerLeftOut(): void
    {
        $recorder = new RecordingSpanRecorder();

        (new SpanScope($recorder, new SpanSubjectDTO('executions.wait', executionId: '7')))
            ->answered(new SpanResultDTO('completed', null, 'support.reply', null, null, null, true));
        (new SpanScope($recorder, new SpanSubjectDTO('operations.execute', operationCode: 'support.reply')))
            ->answered(new SpanResultDTO('queued', '8', null, null, null, null, null));

        self::assertSame('7', $recorder->spans()[0]->executionId);
        self::assertSame('support.reply', $recorder->spans()[0]->operationCode);
        self::assertSame('support.reply', $recorder->spans()[1]->operationCode);
    }

    #[DataProvider('throwableProvider')]
    public function testACallThatThrewIsARefusalOnlyWhenMoneyOrAPolicySaidNo(Throwable $throwable, string $outcome, ?string $errorCode): void
    {
        $recorder = new RecordingSpanRecorder();

        (new SpanScope($recorder, new SpanSubjectDTO('executions.wait', operationCode: 'support.reply', executionId: '7')))->failed($throwable);

        self::assertEquals(new SpanDTO(
            operation: 'executions.wait',
            outcome: $outcome,
            status: null,
            durationMs: $recorder->last()->durationMs,
            executionId: '7',
            operationCode: 'support.reply',
            errorCode: $errorCode,
        ), $recorder->last());
    }

    /**
     * @return Iterator<string, array{Throwable, string, string|null}>
     */
    public static function throwableProvider(): Iterator
    {
        yield 'a budget' => [new BudgetExceededException('no', 'budget_exceeded', 402), 'refused', 'budget_exceeded'];
        yield 'credits' => [new InsufficientCreditsException('no', 'insufficient_credits', 402), 'refused', 'insufficient_credits'];
        yield 'an unnamed 402' => [new SpendRefusedException('no', 'a_402', 402), 'refused', 'a_402'];
        yield 'a policy' => [new PolicyDeniedException('no', 'policy_denied', 403), 'refused', 'policy_denied'];
        yield 'a key' => [new AuthenticationException('no', 'insufficient_scope', 403), 'error', 'insufficient_scope'];
        yield 'the rate of the key' => [new RateLimitException('slow', 'rate_limit_exceeded', 429), 'error', 'rate_limit_exceeded'];
        yield 'a provider' => [new ProviderException('down', 'provider_error', 502), 'error', 'provider_error'];
        yield 'no answer' => [new ConnectionException('gone'), 'error', null];
        yield 'not the SDK' => [new RuntimeException('bug'), 'error', null];
    }

    public function testARecorderThatThrowsReachesNobody(): void
    {
        $recorder = new class implements SpanRecorderInterface {
            public int $calls = 0;

            public function record(SpanDTO $span): void
            {
                ++$this->calls;

                throw new LogicException('the recorder broke');
            }
        };

        $scope = new SpanScope($recorder, new SpanSubjectDTO('operations.execute'));
        $scope->answered(new SpanResultDTO('completed', '7', 'a', null, null, null, false));
        $scope->failed(new ConnectionException('gone'));

        self::assertSame(2, $recorder->calls);
    }
}
