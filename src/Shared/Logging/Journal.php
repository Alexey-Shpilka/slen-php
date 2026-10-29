<?php

declare(strict_types=1);

namespace Slen\Sdk\Shared\Logging;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Throwable;

/** @internal */
final readonly class Journal
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    /** @param array<string, mixed> $context */
    public function debug(string $event, array $context): void
    {
        $this->write(LogLevel::DEBUG, $event, $context);
    }

    /** @param array<string, mixed> $context */
    public function info(string $event, array $context): void
    {
        $this->write(LogLevel::INFO, $event, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $event, array $context): void
    {
        $this->write(LogLevel::WARNING, $event, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $event, array $context): void
    {
        $this->write(LogLevel::ERROR, $event, $context);
    }

    /** @param array<string, mixed> $context */
    private function write(string $level, string $event, array $context): void
    {
        try {
            $this->logger->log($level, $event, $context);
        } catch (Throwable) {
            // The journal is telemetry: it never fails the call it describes.
        }
    }
}
