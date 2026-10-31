<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit\Testing;

use LogicException;
use PHPUnit\Framework\TestCase;
use Slen\Sdk\Observability\SlenSpan;
use Slen\Sdk\Testing\RecordingSpanRecorder;

final class RecordingSpanRecorderTest extends TestCase
{
    public function testItKeepsEverySpanAndTheLastIsTheLatest(): void
    {
        $recordingSpanRecorder = new RecordingSpanRecorder();
        $first = new SlenSpan('operations.execute_async', 'ok', 'queued', 2, '7');
        $second = new SlenSpan('executions.wait', 'ok', 'completed', 9, '7');

        $recordingSpanRecorder->record($first);
        $recordingSpanRecorder->record($second);

        self::assertSame([$first, $second], $recordingSpanRecorder->spans());
        self::assertSame($second, $recordingSpanRecorder->last());
    }

    public function testTheLastSpanOfNoneIsRefused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('RecordingSpanRecorder has recorded no span.');

        (new RecordingSpanRecorder())->last();
    }
}
