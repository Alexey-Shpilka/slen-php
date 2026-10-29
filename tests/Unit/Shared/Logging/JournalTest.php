<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit\Shared\Logging;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Slen\Sdk\Shared\Logging\Journal;
use Slen\Sdk\Tests\Unit\Support\RecordingLogger;
use Stringable;

final class JournalTest extends TestCase
{
    public function testEachLevelWritesItsRecord(): void
    {
        $logger = new RecordingLogger();
        $journal = new Journal($logger);

        $journal->debug('slen.a', ['x' => 1]);
        $journal->info('slen.b', []);
        $journal->warning('slen.c', []);
        $journal->error('slen.d', []);

        self::assertSame(['debug slen.a', 'info slen.b', 'warning slen.c', 'error slen.d'], $logger->events());
        self::assertSame(['x' => 1], $logger->context('slen.a'));
    }

    public function testALoggerThatThrowsDoesNotFailTheCaller(): void
    {
        $journal = new Journal(new class extends AbstractLogger {
            public function log($level, string|Stringable $message, array $context = []): void
            {
                throw new RuntimeException('the log is full');
            }
        });

        $journal->error('slen.failed', []);

        $this->addToAssertionCount(1);
    }
}
