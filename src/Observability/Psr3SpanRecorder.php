<?php

declare(strict_types=1);

namespace Slen\Sdk\Observability;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/** Writes every span as one PSR-3 record, `slen.span`, with its attributes as the context. */
final readonly class Psr3SpanRecorder implements SpanRecorderInterface
{
    private const string MESSAGE = 'slen.span';

    private const array LEVELS = [
        LogLevel::EMERGENCY,
        LogLevel::ALERT,
        LogLevel::CRITICAL,
        LogLevel::ERROR,
        LogLevel::WARNING,
        LogLevel::NOTICE,
        LogLevel::INFO,
        LogLevel::DEBUG,
    ];

    private string $level;

    /** @throws InvalidArgumentException when $level is not a PSR-3 level */
    public function __construct(private LoggerInterface $logger, string $level = LogLevel::INFO)
    {
        $normalised = strtolower(trim($level));

        if (\in_array($normalised, self::LEVELS, true) === false) {
            throw new InvalidArgumentException(
                \sprintf('Slen: "%s" is not a PSR-3 level for spans; use one of %s.', $level, implode(', ', self::LEVELS)),
            );
        }

        $this->level = $normalised;
    }

    public function record(SpanDTO $span): void
    {
        $this->logger->log($this->level, self::MESSAGE, $span->toArray());
    }
}
