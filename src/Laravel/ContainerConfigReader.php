<?php

declare(strict_types=1);

namespace Slen\Sdk\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/** @internal */
final readonly class ContainerConfigReader
{
    public function __construct(private Container $container)
    {
    }

    /** @throws InvalidArgumentException */
    public function logger(): LoggerInterface
    {
        $channel = $this->string('log_channel');

        if ($channel === null) {
            $logger = $this->container->bound(LoggerInterface::class) === true ? $this->container->make(LoggerInterface::class) : null;

            return $logger instanceof LoggerInterface ? $logger : new NullLogger();
        }

        $logManager = $this->container->bound('log') === true ? $this->container->make('log') : null;
        $logger = \is_object($logManager) === true && method_exists($logManager, 'channel') === true ? $logManager->channel($channel) : null;

        return $logger instanceof LoggerInterface
            ? $logger
            : throw new InvalidArgumentException(\sprintf('Slen: log_channel names "%s", and the application has no log channel of that name.', $channel));
    }

    public function string(string $key): ?string
    {
        $value = $this->repository()->get('slen-sdk.' . $key);

        return \is_scalar($value) === true && trim((string) $value) !== '' ? (string) $value : null;
    }

    public function float(string $key): float
    {
        // Floats, not ints: an int cast makes 0.5 a zero, and zero is no timeout.
        return (float) $this->string($key);
    }

    public function int(string $key): int
    {
        return (int) $this->string($key);
    }

    private function repository(): Repository
    {
        /** @var Repository */
        return $this->container->make('config');
    }
}
