<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit\Observability;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use Slen\Sdk\Exceptions\InsufficientCreditsException;
use Slen\Sdk\Modules\Operations\Options\ExecuteOptions;
use Slen\Sdk\Observability\SlenSpan;
use Slen\Sdk\Testing\FakeHttpClient;
use Slen\Sdk\Testing\RecordingSpanRecorder;
use Slen\Sdk\Tests\Paths;
use Slen\Sdk\Tests\Unit\Support\Answers;

/**
 * The span is contract/span-attributes.json, the file the TypeScript suite builds its span tests from too.
 */
final class SlenSpanTest extends TestCase
{
    private const string CONTRACT = Paths::CONTRACT . '/span-attributes.json';

    private const string PROMPT_LIKE = '/prompt|message|content|input|output|completion|text|body/i';

    public function testASpanHasTheFieldsOfTheContractInItsOrderAndNoPlaceForAPrompt(): void
    {
        $properties = (new ReflectionClass(SlenSpan::class))->getProperties();

        self::assertSame(
            array_column(self::contract()['fields'], 'field'),
            array_map(static function (ReflectionProperty $reflectionProperty): string {
                return $reflectionProperty->getName();
            }, $properties),
        );

        foreach (self::contract()['fields'] as $index => $field) {
            $type = $properties[$index]->getType();

            self::assertInstanceOf(ReflectionNamedType::class, $type);
            self::assertSame($field['nullable'], $type->allowsNull(), $field['field']);
            self::assertDoesNotMatchRegularExpression(self::PROMPT_LIKE, $field['field']);
            self::assertDoesNotMatchRegularExpression(self::PROMPT_LIKE, $field['attribute']);
        }
    }

    public function testTheAttributesAreTheNamesOfTheContractInTheOrderOfTheFields(): void
    {
        $span = new SlenSpan(
            operation: 'operations.execute',
            outcome: 'ok',
            status: 'completed',
            durationMs: 12,
            executionId: '74438200973594700',
            operationCode: 'support.reply',
            estimated: '0.00412500',
            actual: '0.00390000',
            errorCode: 'none',
            cacheHit: true,
        );

        self::assertSame(array_column(self::contract()['fields'], 'attribute'), array_keys($span->toArray()));
        self::assertSame('0.00412500', $span->toArray()['estimated']);
        self::assertSame(12, $span->toArray()['duration_ms']);
    }

    public function testANullIsLeftOutAndFalseIsKept(): void
    {
        $span = new SlenSpan('executions.wait', 'error', null, 3, cacheHit: false);

        self::assertSame(
            ['operation' => 'executions.wait', 'outcome' => 'error', 'duration_ms' => 3, 'cache_hit' => false],
            $span->toArray(),
        );
    }

    /**
     * Every name the contract gives to php is one a call writes, and the calls write no other: a run, an async run, a wait.
     */
    public function testTheCallsWriteTheirSpansUnderTheNamesAndOutcomesOfTheContract(): void
    {
        $fakeHttpClient = (new FakeHttpClient())
            ->pushJson(200, ['data' => Answers::execution()])
            ->pushJson(202, ['data' => Answers::execution(['status' => 'queued', 'output' => null])])
            ->pushJson(200, ['data' => Answers::status()])
            ->pushJson(402, Answers::refusal('insufficient_credits'));
        $recordingSpanRecorder = new RecordingSpanRecorder();
        $slenSdk = Answers::sdk($fakeHttpClient, $recordingSpanRecorder);

        $slenSdk->operations()->execute('support.reply', ['question' => 'hello'], new ExecuteOptions(idempotencyKey: 'order-1:reply'));
        $slenSdk->operations()->executeAsync('support.reply', ['question' => 'hello'], new ExecuteOptions(idempotencyKey: 'order-2:reply'));
        $slenSdk->executions()->wait('74438200973594700');

        try {
            $slenSdk->operations()->execute('support.reply', ['question' => 'hello'], new ExecuteOptions(idempotencyKey: 'order-3:reply'));
        } catch (InsufficientCreditsException) {
        }

        $written = array_map(static function (SlenSpan $slenSpan): string {
            return $slenSpan->operation;
        }, $recordingSpanRecorder->spans());
        $named = array_keys(array_filter(
            self::contract()['operations'],
            static function (array $operation): bool {
                return \in_array('php', $operation['sdks'], true);
            },
        ));
        sort($named);
        $unique = array_values(array_unique($written));
        sort($unique);

        self::assertSame($named, $unique);
        self::assertSame(['ok', 'ok', 'ok', 'refused'], array_map(static function (SlenSpan $slenSpan): string {
            return $slenSpan->outcome;
        }, $recordingSpanRecorder->spans()));
        self::assertSame([], array_diff(['ok', 'refused', 'error'], array_keys(self::contract()['outcomes'])));
    }

    /**
     * @return array{
     *     fields: list<array{field: string, attribute: string, nullable: bool, holds: string}>,
     *     outcomes: array<string, string>,
     *     operations: array<string, array{sdks: list<string>, means: string}>
     * }
     */
    private static function contract(): array
    {
        /** @var array{fields: list<array{field: string, attribute: string, nullable: bool, holds: string}>, outcomes: array<string, string>, operations: array<string, array{sdks: list<string>, means: string}>} */
        return json_decode((string) file_get_contents(self::CONTRACT), true, 512, JSON_THROW_ON_ERROR);
    }
}
