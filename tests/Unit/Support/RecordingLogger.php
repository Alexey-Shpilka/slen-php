<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * A PSR-3 logger that keeps every record.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<mixed>}> */
    public array $records = [];

    /**
     * @param string $name whose records these are: a request of a worker, a tenant
     */
    public function __construct(public readonly string $name = '')
    {
    }

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => \is_string($level) === true ? $level : '', 'message' => (string) $message, 'context' => $context];
    }

    /**
     * @return list<string> "level message" of each record, in order
     */
    public function events(): array
    {
        return array_map(static function (array $record): string {
            return $record['level'] . ' ' . $record['message'];
        }, $this->records);
    }

    /**
     * @return array<mixed>
     */
    public function context(string $message): array
    {
        foreach ($this->records as $record) {
            if ($record['message'] === $message) {
                return $record['context'];
            }
        }

        return [];
    }
}
