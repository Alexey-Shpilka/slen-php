<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit\Modules\Executions;

use DateTimeImmutable;
use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slen\Sdk\Exceptions\CustomerNotFoundException;
use Slen\Sdk\Exceptions\InvalidRequestException;
use Slen\Sdk\Exceptions\InvalidResponseException;
use Slen\Sdk\Modules\Executions\Data\AttemptCostBreakdown;
use Slen\Sdk\Modules\Executions\Data\AttemptSummary;
use Slen\Sdk\Modules\Executions\Data\FlowExecutionEvent;
use Slen\Sdk\Modules\Executions\Data\FlowExecutionNode;
use Slen\Sdk\Modules\Operations\Enums\ExecutionStatus;
use Slen\Sdk\Observability\SlenSpan;
use Slen\Sdk\SlenSdk;
use Slen\Sdk\Testing\FakeHttpClient;
use Slen\Sdk\Testing\RecordingSpanRecorder;
use Slen\Sdk\Tests\Unit\Support\Answers;
use stdClass;

final class ExecutionsFacadeTest extends TestCase
{
    public function testGetReadsTheStateOfTheRunOnceAndLeavesNoSpan(): void
    {
        $fakeHttpClient = (new FakeHttpClient())->pushJson(200, ['data' => Answers::pending('running', 1)]);
        $recordingSpanRecorder = new RecordingSpanRecorder();

        $executionStatusView = Answers::sdk($fakeHttpClient, $recordingSpanRecorder)->executions()->get('74438200973594700');

        self::assertSame('GET', $fakeHttpClient->lastRequest()->getMethod());
        self::assertSame('https://api.slen.ai/api/v1/executions/74438200973594700/status', (string) $fakeHttpClient->lastRequest()->getUri());
        self::assertSame(ExecutionStatus::Running, $executionStatusView->status);
        self::assertSame(1, $executionStatusView->retryAfterSeconds);
        self::assertSame([], $recordingSpanRecorder->spans());
    }

    public function testAnIdIsOnePathSegmentWhateverItHoldsAndAnEmptyOneIsRefused(): void
    {
        $fakeHttpClient = (new FakeHttpClient())->pushJson(200, ['data' => Answers::status()]);
        $slenSdk = Answers::sdk($fakeHttpClient);

        $slenSdk->executions()->get('7/../8');

        self::assertSame('/api/v1/executions/7%2F..%2F8/status', $fakeHttpClient->lastRequest()->getUri()->getPath());

        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('the id of GET /v1/executions/{id} is empty');

        $slenSdk->executions()->trace('');
    }

    public function testAWaitOnARunThatIsOverReadsItOnceAndLeavesOneSpan(): void
    {
        $answer = Answers::status([
            'cost' => ['estimated' => '0.00412500', 'actual' => '0.00400000', 'incomplete' => false],
            'cache' => ['hit' => false, 'cached_at' => null],
        ]);
        $fakeHttpClient = (new FakeHttpClient())->pushJson(200, ['data' => $answer]);
        $recordingSpanRecorder = new RecordingSpanRecorder();

        $executionStatusView = Answers::sdk($fakeHttpClient, $recordingSpanRecorder)->executions()->wait('74438200973594700');

        self::assertTrue($executionStatusView->isCompleted());
        self::assertCount(1, $fakeHttpClient->requests());
        self::assertEquals([new SlenSpan(
            operation: 'executions.wait',
            outcome: 'ok',
            status: 'completed',
            durationMs: $recordingSpanRecorder->last()->durationMs,
            executionId: '74438200973594700',
            operationCode: 'support.reply',
            estimated: '0.00412500',
            actual: '0.00400000',
            cacheHit: false,
        )], $recordingSpanRecorder->spans());
    }

    public function testAWaitOnAnEmptyIdSendsNothingAndLeavesNoSpan(): void
    {
        $fakeHttpClient = new FakeHttpClient();
        $recordingSpanRecorder = new RecordingSpanRecorder();

        try {
            Answers::sdk($fakeHttpClient, $recordingSpanRecorder)->executions()->wait(' ');
            self::fail('the empty id was not refused');
        } catch (InvalidRequestException) {
            self::assertSame([], $fakeHttpClient->requests());
        }

        self::assertSame([], $recordingSpanRecorder->spans());
    }

    public function testAWaitOnARunThatIsNotThereThrowsAtOnceAndItsSpanNamesTheRun(): void
    {
        $fakeHttpClient = (new FakeHttpClient())->pushJson(404, Answers::refusal('customer_not_found'));
        $recordingSpanRecorder = new RecordingSpanRecorder();

        try {
            Answers::sdk($fakeHttpClient, $recordingSpanRecorder)->executions()->wait('7');
            self::fail('the 404 was not thrown');
        } catch (CustomerNotFoundException) {
            self::assertCount(1, $fakeHttpClient->requests());
        }

        self::assertEquals(
            new SlenSpan('executions.wait', 'error', null, $recordingSpanRecorder->last()->durationMs, '7', errorCode: 'customer_not_found'),
            $recordingSpanRecorder->last(),
        );
    }

    public function testTraceReadsTheRunItsNodesTheirAttemptsAndTheEvents(): void
    {
        $fakeHttpClient = (new FakeHttpClient())->pushJson(200, ['data' => self::trace()]);

        $executionTrace = Answers::sdk($fakeHttpClient)->executions()->trace('74438200973594700');

        self::assertSame('https://api.slen.ai/api/v1/executions/74438200973594700', (string) $fakeHttpClient->lastRequest()->getUri());
        self::assertSame('Support replies', $executionTrace->flowName);
        self::assertSame(ExecutionStatus::Completed, $executionTrace->status);
        self::assertEquals(new DateTimeImmutable('2026-10-17T12:00:00.120+00:00'), $executionTrace->startedAt);
        self::assertSame(['ai_model', 'return_response'], array_map(static function (FlowExecutionNode $node): string {
            return $node->nodeType;
        }, $executionTrace->nodes));
        self::assertNull($executionTrace->nodes[1]->outputPort);
        self::assertEquals(
            [new AttemptSummary(
                '74438200973596001',
                1,
                '74438200973597001',
                null,
                'succeeded',
                null,
                840,
                '0.00390000',
                'req_abc',
                'gpt-4o',
                1100,
                179,
                512,
                0,
                0,
                0,
                [],
                new AttemptCostBreakdown('0.00147000', '0.00064000', '0.00000000', '0.00179000', [], '0.00390000'),
                false,
            )],
            $executionTrace->nodes[0]->attempts,
        );
        self::assertEquals(
            new FlowExecutionEvent(2, 'reserved', 'reply', new DateTimeImmutable('2026-10-17T12:00:00.150+00:00'), ['amount' => '0.00412500']),
            $executionTrace->events[1],
        );
    }

    public function testAnAttemptReadsItsPaidToolsAndACostSlenCouldNotPriceWhole(): void
    {
        $fakeHttpClient = (new FakeHttpClient())->pushJson(200, ['data' => self::trace([
            'tool_calls' => ['web_search' => 2],
            'cost_breakdown' => [
                'input' => '0.00147000',
                'cached_input' => '0.00064000',
                'cache_write' => '0.00000000',
                'output' => '0.00179000',
                'tools' => ['web_search' => '0.02000000'],
                'total' => '0.02390000',
            ],
            'cost' => '0.02390000',
            'cost_incomplete' => true,
        ])]);

        $attempt = Answers::sdk($fakeHttpClient)->executions()->trace('74438200973594700')->nodes[0]->attempts[0];

        self::assertSame(['web_search' => 2], $attempt->toolCalls);
        self::assertSame(['web_search' => '0.02000000'], $attempt->costBreakdown?->tools);
        self::assertTrue($attempt->costIncomplete);
    }

    public function testAnAttemptWithoutItsTokensAndItsBreakdownReadsThemAsNull(): void
    {
        $fakeHttpClient = (new FakeHttpClient())->pushJson(200, ['data' => self::trace([], [
            'input_tokens',
            'output_tokens',
            'cached_input_tokens',
            'cache_write_tokens',
            'cache_write_long_tokens',
            'reasoning_tokens',
            'tool_calls',
            'cost_breakdown',
            'cost_incomplete',
        ])]);

        $attempt = Answers::sdk($fakeHttpClient)->executions()->trace('74438200973594700')->nodes[0]->attempts[0];

        self::assertSame(
            [null, null, null, null, null, null, null, null, null],
            [
                $attempt->inputTokens,
                $attempt->outputTokens,
                $attempt->cachedInputTokens,
                $attempt->cacheWriteTokens,
                $attempt->cacheWriteLongTokens,
                $attempt->reasoningTokens,
                $attempt->toolCalls,
                $attempt->costBreakdown,
                $attempt->costIncomplete,
            ],
        );
    }

    /**
     * @param array<string, mixed> $attempt
     */
    #[DataProvider('unreadableAttemptProvider')]
    public function testAnAttemptWhoseToolsAreNotCountsAndAmountsIsUnreadable(array $attempt, string $field): void
    {
        $fakeHttpClient = (new FakeHttpClient())->pushJson(200, ['data' => self::trace($attempt)]);

        $this->expectException(InvalidResponseException::class);
        $this->expectExceptionMessage('"' . $field . '"');

        Answers::sdk($fakeHttpClient)->executions()->trace('74438200973594700');
    }

    /**
     * @return Iterator<string, array{array<string, mixed>, string}>
     */
    public static function unreadableAttemptProvider(): Iterator
    {
        $breakdown = ['input' => '0', 'cached_input' => '0', 'cache_write' => '0', 'output' => '0', 'total' => '0'];

        yield 'a count as a string' => [['tool_calls' => ['web_search' => '2']], 'tool_calls'];
        yield 'counts as a list' => [['tool_calls' => [2]], 'tool_calls'];
        yield 'an amount as a float' => [['cost_breakdown' => ['tools' => ['web_search' => 0.02], ...$breakdown]], 'tools'];
        yield 'a breakdown without its tools' => [['cost_breakdown' => $breakdown], 'tools'];
        yield 'an incomplete cost as a string' => [['cost_incomplete' => 'no'], 'cost_incomplete'];
    }

    public function testAModelNodeReadsTheAnswerItLeftAndWhetherItWasKept(): void
    {
        $refusal = ['class' => 'unavailable', 'code' => 'pool_unavailable', 'http_status' => 503, 'retry_after_seconds' => null];
        $fakeHttpClient = (new FakeHttpClient())
            ->pushJson(200, ['data' => self::trace()])
            ->pushJson(200, ['data' => self::trace([], [], ['output_port' => 'unavailable', 'output' => ['error' => $refusal], 'output_truncated' => true])])
            ->pushJson(200, ['data' => self::trace([], [], ['output' => null, 'output_stored' => 'disabled_by_project'])]);
        $executions = Answers::sdk($fakeHttpClient)->executions();

        $answered = $executions->trace('74438200973594700')->nodes;
        $refused = $executions->trace('74438200973594700')->nodes[0];
        $unkept = $executions->trace('74438200973594700')->nodes[0];

        self::assertSame('Your order ships on Monday.', $answered[0]->output['content'] ?? null);
        self::assertSame([false, 'stored'], [$answered[0]->outputTruncated, $answered[0]->outputStored]);
        self::assertSame([null, null, null], [$answered[1]->output, $answered[1]->outputTruncated, $answered[1]->outputStored]);
        self::assertSame(['error' => $refusal], $refused->output);
        self::assertTrue($refused->outputTruncated);
        self::assertSame([null, 'disabled_by_project'], [$unkept->output, $unkept->outputStored]);
    }

    public function testANodeReadsItsEstimateAndOnceItsHoldIsCapturedItsFact(): void
    {
        $fakeHttpClient = (new FakeHttpClient())
            ->pushJson(200, ['data' => self::trace()])
            ->pushJson(200, ['data' => self::trace([], [], ['cost' => '0.00412500', 'cost_status' => 'estimated', 'cost_incomplete' => true])]);
        $executions = Answers::sdk($fakeHttpClient)->executions();

        $settled = $executions->trace('74438200973594700')->nodes;
        $held = $executions->trace('74438200973594700')->nodes[0];

        $model = $settled[0];

        self::assertSame(['0.00390000', '0.00412500', 'actual', false], [$model->cost, $model->estimatedCost, $model->costStatus, $model->costIncomplete]);
        self::assertSame([null, null, null], [$settled[1]->estimatedCost, $settled[1]->costStatus, $settled[1]->costIncomplete]);
        self::assertSame(['0.00412500', '0.00412500', 'estimated', true], [$held->cost, $held->estimatedCost, $held->costStatus, $held->costIncomplete]);
    }

    public function testAModelNodeWhoseKeptStateIsNotAStringIsUnreadable(): void
    {
        $fakeHttpClient = (new FakeHttpClient())->pushJson(200, ['data' => self::trace([], [], ['output_stored' => true])]);

        $this->expectException(InvalidResponseException::class);
        $this->expectExceptionMessage('"output_stored"');

        Answers::sdk($fakeHttpClient)->executions()->trace('74438200973594700');
    }

    public function testATraceWhoseNodesAreNotObjectsIsUnreadable(): void
    {
        $fakeHttpClient = (new FakeHttpClient())->pushJson(200, ['data' => ['nodes' => ['reply']] + self::trace()]);

        $this->expectException(InvalidResponseException::class);
        $this->expectExceptionMessage('"nodes"');

        Answers::sdk($fakeHttpClient)->executions()->trace('74438200973594700');
    }

    public function testTheFacadesAreBuiltOncePerSdk(): void
    {
        $slenSdk = Answers::sdk(new FakeHttpClient());

        self::assertSame($slenSdk->executions(), $slenSdk->executions());
        self::assertSame($slenSdk->operations(), $slenSdk->operations());
        self::assertNotSame(SlenSdk::create($slenSdk->config())->executions(), $slenSdk->executions());
    }

    /**
     * @param array<string, mixed> $attempt  on top of the one attempt of the model node
     * @param list<string>         $leaveOut the keys that attempt goes without
     * @param array<string, mixed> $node     on top of the model node
     *
     * @return array<string, mixed>
     */
    private static function trace(array $attempt = [], array $leaveOut = [], array $node = []): array
    {
        return [
            'execution_id' => '74438200973594700',
            'flow_id' => '74438200973590001',
            'flow_version_id' => '74438200973591001',
            'flow_name' => 'Support replies',
            'operation' => 'support.reply',
            'operation_id' => '74438200973595001',
            'status' => 'completed',
            'error_code' => null,
            'error_message' => null,
            'cost' => ['estimated' => '0.00412500', 'actual' => '0.00390000', 'incomplete' => false],
            'cache' => ['hit' => false, 'cached_at' => null],
            'started_at' => '2026-10-17T12:00:00.120+00:00',
            'finished_at' => '2026-10-17T12:00:01.010+00:00',
            'duration_ms' => 890,
            'warnings' => [],
            'nodes' => [
                [
                    'node_key' => 'reply',
                    'node_type' => 'ai_model',
                    'status' => 'completed',
                    'output_port' => 'success',
                    'cost' => '0.00390000',
                    'estimated_cost' => '0.00412500',
                    'cost_status' => 'actual',
                    'cost_incomplete' => false,
                    'duration_ms' => 850,
                    'started_at' => '2026-10-17T12:00:00.130+00:00',
                    'finished_at' => '2026-10-17T12:00:00.980+00:00',
                    'metadata' => ['ai_request_id' => '74438200973595001'],
                    'attempts' => [array_diff_key([
                        'attempt_id' => '74438200973596001',
                        'attempt_number' => 1,
                        'connection_id' => '74438200973597001',
                        'pool_id' => null,
                        'status' => 'succeeded',
                        'error_code' => null,
                        'latency_ms' => 840,
                        'cost' => '0.00390000',
                        'provider_request_id' => 'req_abc',
                        'model_code' => 'gpt-4o',
                        'input_tokens' => 1100,
                        'output_tokens' => 179,
                        'cached_input_tokens' => 512,
                        'cache_write_tokens' => 0,
                        'cache_write_long_tokens' => 0,
                        'reasoning_tokens' => 0,
                        'tool_calls' => new stdClass(),
                        'cost_breakdown' => [
                            'input' => '0.00147000',
                            'cached_input' => '0.00064000',
                            'cache_write' => '0.00000000',
                            'output' => '0.00179000',
                            'tools' => new stdClass(),
                            'total' => '0.00390000',
                        ],
                        'cost_incomplete' => false,
                        ...$attempt,
                    ], array_flip($leaveOut))],
                    'label' => 'Reply',
                    'model' => 'gpt-4o',
                    'code' => null,
                    'output' => [
                        'model' => 'gpt-4o',
                        'content' => 'Your order ships on Monday.',
                        'finish_reason' => 'stop',
                        'tool_calls' => [],
                        'usage' => ['input_tokens' => 1100, 'output_tokens' => 179, 'cached_input_tokens' => 512],
                    ],
                    'output_truncated' => false,
                    'output_stored' => 'stored',
                    ...$node,
                ],
                [
                    'node_key' => 'answer',
                    'node_type' => 'return_response',
                    'status' => 'completed',
                    'output_port' => null,
                    'cost' => null,
                    'duration_ms' => 1,
                    'started_at' => '2026-10-17T12:00:00.990+00:00',
                    'finished_at' => null,
                    'metadata' => null,
                    'attempts' => [],
                ],
            ],
            'events' => [
                ['seq' => 1, 'type' => 'accepted', 'node_key' => null, 'occurred_at' => '2026-10-17T12:00:00.120+00:00', 'payload' => null],
                [
                    'seq' => 2,
                    'type' => 'reserved',
                    'node_key' => 'reply',
                    'occurred_at' => '2026-10-17T12:00:00.150+00:00',
                    'payload' => ['amount' => '0.00412500'],
                ],
            ],
        ];
    }
}
