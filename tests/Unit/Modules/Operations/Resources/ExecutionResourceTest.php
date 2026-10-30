<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit\Modules\Operations\Resources;

use DateTimeImmutable;
use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slen\Sdk\Exceptions\InvalidResponseException;
use Slen\Sdk\Modules\Operations\Data\ExecutionCache;
use Slen\Sdk\Modules\Operations\Data\ExecutionCost;
use Slen\Sdk\Modules\Operations\Data\ExecutionResult;
use Slen\Sdk\Modules\Operations\Data\WaitingOn;
use Slen\Sdk\Modules\Operations\Enums\ExecutionStatus;
use Slen\Sdk\Modules\Operations\Resources\ExecutionResource;
use Slen\Sdk\Tests\Unit\Support\Answers;

final class ExecutionResourceTest extends TestCase
{
    public function testEveryFieldOfTheAnswerIsRead(): void
    {
        $executionResult = (new ExecutionResource())->map(Answers::execution([
            'status' => 'waiting',
            'output' => null,
            'cost' => ['estimated' => '0.01000000', 'actual' => null],
            'waiting_on' => ['node_key' => 'delay', 'type' => 'queue_node', 'resume_at' => '2026-10-17T12:05:00+00:00'],
            'retry_after_seconds' => 300,
        ]));

        self::assertEquals(new ExecutionResult(
            executionId: '74438200973594700',
            status: ExecutionStatus::Waiting,
            operationCode: 'support.reply',
            output: null,
            errorCode: null,
            errorMessage: null,
            cost: new ExecutionCost('0.01000000', null),
            cache: new ExecutionCache(false, null),
            waitingOn: new WaitingOn('delay', 'queue_node', new DateTimeImmutable('2026-10-17T12:05:00+00:00')),
            traceUrl: '/v1/executions/74438200973594700',
            retryAfterSeconds: 300,
            error: null,
            replayed: false,
        ), $executionResult);
    }

    /**
     * @param array<string, mixed> $answer
     */
    #[DataProvider('unreadableProvider')]
    public function testAnAnswerThatIsNotARunIsUnreadableNeverADefault(array $answer, string $field): void
    {
        $this->expectException(InvalidResponseException::class);
        $this->expectExceptionMessage('"' . $field . '"');

        (new ExecutionResource())->map($answer);
    }

    /**
     * @return Iterator<string, array{array<string, mixed>, string}>
     */
    public static function unreadableProvider(): Iterator
    {
        $answer = Answers::execution();
        unset($answer['replayed']);

        yield 'no replayed' => [$answer, 'replayed'];
        yield 'a status the SDK does not know' => [Answers::execution(['status' => 'paused']), 'status'];
        yield 'a cost as a float' => [Answers::execution(['cost' => ['estimated' => 0.0041, 'actual' => null]]), 'estimated'];
        yield 'no cache' => [Answers::execution(['cache' => null]), 'cache'];
        yield 'an error without a message' => [Answers::execution(['error' => ['code' => 'x']]), 'message'];
        yield 'an execution id as a number' => [Answers::execution(['execution_id' => 74438200973594700]), 'execution_id'];
    }

    #[DataProvider('statusProvider')]
    public function testAStatusIsPendingOrFinished(string $status, bool $pending): void
    {
        $executionResult = (new ExecutionResource())->map(Answers::execution(['status' => $status]));

        self::assertSame($pending, $executionResult->isPending());
        self::assertSame(!$pending, $executionResult->isFinished());
        self::assertSame($status === 'completed', $executionResult->isCompleted());
        self::assertSame($status === 'rejected', $executionResult->isRejected());
    }

    /**
     * @return Iterator<string, array{string, bool}>
     */
    public static function statusProvider(): Iterator
    {
        foreach (ExecutionStatus::cases() as $executionStatus) {
            yield $executionStatus->value => [
                $executionStatus->value,
                \in_array($executionStatus, [ExecutionStatus::Queued, ExecutionStatus::Waiting, ExecutionStatus::Running], true),
            ];
        }
    }
}
