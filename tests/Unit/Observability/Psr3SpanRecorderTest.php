<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit\Observability;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Slen\Sdk\Observability\NullSpanRecorder;
use Slen\Sdk\Observability\Psr3SpanRecorder;
use Slen\Sdk\Observability\SpanDTO;
use Slen\Sdk\Tests\Unit\Support\RecordingLogger;

final class Psr3SpanRecorderTest extends TestCase
{
    public function testASpanIsOneRecordWithItsAttributesAsTheContext(): void
    {
        $logger = new RecordingLogger();

        (new Psr3SpanRecorder($logger))->record(new SpanDTO('operations.execute', 'ok', 'completed', 5, '7', 'support.reply', cacheHit: false));

        self::assertSame([[
            'level' => 'info',
            'message' => 'slen.span',
            'context' => [
                'operation' => 'operations.execute',
                'outcome' => 'ok',
                'status' => 'completed',
                'duration_ms' => 5,
                'execution_id' => '7',
                'operation_code' => 'support.reply',
                'cache_hit' => false,
            ],
        ]], $logger->records);
    }

    public function testTheLevelIsAnyPsr3LevelWrittenAnyCase(): void
    {
        $logger = new RecordingLogger();

        (new Psr3SpanRecorder($logger, ' Warning '))->record(new SpanDTO('executions.wait', 'error', null, 1));

        self::assertSame(['warning slen.span'], $logger->events());
    }

    public function testALevelPsr3DoesNotKnowIsRefusedWhenTheRecorderIsBuilt(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"verbose" is not a PSR-3 level');

        new Psr3SpanRecorder(new RecordingLogger(), 'verbose');
    }

    public function testTheNullRecorderTakesASpanAndDoesNothing(): void
    {
        (new NullSpanRecorder())->record(new SpanDTO('operations.execute', 'ok', 'completed', 1));

        $this->expectNotToPerformAssertions();
    }
}
