<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit;

use Closure;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use ReflectionFunction;
use ReflectionObject;
use ReflectionProperty;
use Slen\Sdk\Exceptions\SlenException;
use Slen\Sdk\IdempotencyKeys;
use Slen\Sdk\Laravel\Slen;
use Slen\Sdk\Modules\Operations\Options\ExecuteOptions;
use Slen\Sdk\Modules\Webhooks\WebhooksFacade;
use Slen\Sdk\SlenConfig;
use Slen\Sdk\SlenSdk;
use Slen\Sdk\Tests\Unit\Support\Answers;
use Slen\Sdk\Tests\Unit\Support\ConstantHttpClient;
use Slen\Sdk\Tests\Unit\Support\LaravelApplication;
use Slen\Sdk\Tests\Unit\Support\RecordingLogger;
use Slen\Sdk\Tests\Unit\Support\SourceTree;
use Stringable;

/**
 * A long-running worker (FrankenPHP, Octane, RoadRunner, Swoole) builds the SDK once and serves many requests with it.
 */
final class WorkerModeTest extends TestCase
{
    /** The statics of the public surface: named constructors and pure functions, and what Laravel requires. */
    private const array STATIC_METHODS = [
        SlenSdk::class . '::create',
        WebhooksFacade::class . '::standalone',
        IdempotencyKeys::class . '::generate',
        IdempotencyKeys::class . '::forJob',
        SlenException::class . '::type',
        Slen::class . '::getFacadeAccessor',
    ];

    private const int WARM_UP = 100;

    private const int REQUESTS = 1_000;

    private const int MOST_BYTES_KEPT = 64 * 1_024;

    private const int INTERLEAVED = 50;

    public function testNothingIsStaticButTheNamedConstructorsAndPureFunctionsOfThePublicSurface(): void
    {
        $sourceTree = new SourceTree();
        $offences = [];

        foreach ($sourceTree->classes() as $reflectionClass) {
            foreach ($reflectionClass->getProperties(ReflectionProperty::IS_STATIC) as $reflectionProperty) {
                if ($reflectionProperty->getDeclaringClass()->getName() === $reflectionClass->getName()) {
                    $offences[] = $reflectionClass->getName() . '::$' . $reflectionProperty->getName();
                }
            }

            foreach ($sourceTree->ownMethods($reflectionClass) as $reflectionMethod) {
                $name = $reflectionClass->getName() . '::' . $reflectionMethod->getName();

                if ($reflectionMethod->isStatic() === true && \in_array($name, self::STATIC_METHODS, true) === false) {
                    $offences[] = $name . '()';
                }
            }
        }

        self::assertSame([], $offences, 'A worker serves many requests: state and helpers belong to instances.');
    }

    public function testOneSdkServesAThousandRequestsEachWithItsOwnLoggerAndKeepsNothing(): void
    {
        $shared = new RecordingLogger('worker');
        $psr17Factory = new Psr17Factory();
        $http = new ConstantHttpClient(200, ['data' => Answers::execution()]);
        $sdk = SlenSdk::create((new SlenConfig('sl_live_worker', retries: 0, logger: $shared))->withHttpClient($http, $psr17Factory, $psr17Factory));
        $strays = [];

        for ($request = 0; $request < self::WARM_UP; ++$request) {
            $strays = [...$strays, ...$this->serve($sdk, $request)];
        }

        gc_collect_cycles();
        $before = memory_get_usage();

        for ($request = self::WARM_UP; $request < self::REQUESTS; ++$request) {
            $strays = [...$strays, ...$this->serve($sdk, $request)];
        }

        gc_collect_cycles();
        $kept = memory_get_usage() - $before;

        self::assertSame([], $strays, 'every record is in the logger of the request that wrote it');
        self::assertSame([], $shared->records, 'the shared SDK wrote nothing of the requests');
        self::assertLessThan(self::MOST_BYTES_KEPT, $kept, \sprintf('%d bytes kept over %d requests', $kept, self::REQUESTS - self::WARM_UP));
    }

    /**
     * A copy changes nothing it was made from: once the copy of the next request is made, the copy of the
     * previous one and the shared SDK still write where they did.
     */
    public function testACopyLeavesTheSharedSdkAndTheCopiesMadeBeforeItAsTheyWere(): void
    {
        $shared = new RecordingLogger('shared');
        $psr17Factory = new Psr17Factory();
        $http = new ConstantHttpClient(200, ['data' => Answers::execution()]);
        $sdk = SlenSdk::create((new SlenConfig('sl_live_worker', retries: 0, logger: $shared))->withHttpClient($http, $psr17Factory, $psr17Factory));
        $loggers = [];
        $previous = null;

        for ($request = 0; $request < self::INTERLEAVED; ++$request) {
            $logger = new RecordingLogger('request-' . $request . ':reply');
            $copy = $sdk->withLogger($logger);

            if ($previous !== null) {
                self::call($previous[0], $previous[1]);
            }

            self::call($sdk, 'shared-' . $request);
            self::call($copy, $logger->name);
            $loggers[] = $logger;
            $previous = [$copy, $logger->name];
        }

        $strays = [];
        $counts = [];

        foreach ($loggers as $logger) {
            $strays = [...$strays, ...self::recordsOfOthers($logger, $logger->name)];
            $counts[] = \count($logger->records);
        }

        self::assertSame([], [...$strays, ...self::recordsOfOthers($shared, 'shared-')]);
        self::assertSame([...array_fill(0, self::INTERLEAVED - 1, 4), 2], $counts, 'two records a call: its own, and the next request calling it');
        self::assertCount(2 * self::INTERLEAVED, $shared->records);
    }

    /**
     * Octane serves a request from a clone of the application. The SDK built there keeps what it was built from —
     * the clients, the logger — and not the clone: the application's own logger may hold the application, which
     * lives as long as the worker anyway.
     */
    public function testTheSdkOfTheApplicationDoesNotReachTheSandboxItWasBuiltIn(): void
    {
        $application = LaravelApplication::withTheProvider([
            'api_key' => 'sl_live_provider',
            'timeout' => 65.0,
            'connect_timeout' => 5.0,
            'retries' => 2,
            'webhook_tolerance' => 300,
        ]);
        $application->instance(LoggerInterface::class, new class ($application) extends AbstractLogger {
            public function __construct(public readonly LaravelApplication $application)
            {
            }

            public function log($level, string|Stringable $message, array $context = []): void
            {
            }
        });
        $sandbox = clone $application;

        $sdk = $sandbox->make(SlenSdk::class);

        self::assertTrue(self::reaches($sdk, $application), 'the logger of the application holds the application');
        self::assertFalse(self::reaches($sdk, $sandbox), 'an SDK that holds the sandbox keeps a request of the worker alive');
    }

    public function testTheWalkFindsAnObjectHeldDeepInsideOrCapturedByAClosure(): void
    {
        $application = new LaravelApplication();
        $held = (object) ['list' => [(object) ['deep' => $application]]];
        $captured = (object) ['closure' => static function () use ($application): LaravelApplication {
            return $application;
        }];

        self::assertTrue(self::reaches($held, $application));
        self::assertTrue(self::reaches($captured, $application));
        self::assertFalse(self::reaches($held, new LaravelApplication()));
    }

    /**
     * @return list<string> the records that are not of the request $request
     */
    private function serve(SlenSdk $sdk, int $request): array
    {
        $key = 'request-' . $request . ':reply';
        $logger = new RecordingLogger($key);

        $sdk->withLogger($logger)->operations()->execute('support.reply', ['messages' => []], new ExecuteOptions(idempotencyKey: $key));

        $strays = [];

        foreach ($logger->records as $record) {
            $answeredFor = $record['context']['request_id'] ?? $logger->name;

            if ($answeredFor !== $logger->name) {
                $strays[] = $logger->name . ' holds a record of ' . (\is_string($answeredFor) === true ? $answeredFor : '?');
            }
        }

        if (\count($logger->records) !== 2) {
            $strays[] = $logger->name . ' holds ' . \count($logger->records) . ' records';
        }

        return $strays;
    }

    private static function call(SlenSdk $sdk, string $key): void
    {
        $sdk->operations()->execute('support.reply', ['messages' => []], new ExecuteOptions(idempotencyKey: $key));
    }

    /**
     * A record that names no request (the one written before the wire) is left to the count.
     *
     * @return list<string> the records of $logger answered for a request whose key does not start with $prefix
     */
    private static function recordsOfOthers(RecordingLogger $logger, string $prefix): array
    {
        $strays = [];

        foreach ($logger->records as $record) {
            $answeredFor = $record['context']['request_id'] ?? $prefix;

            if (\is_string($answeredFor) === false || str_starts_with($answeredFor, $prefix) === false) {
                $strays[] = $logger->name . ' holds a record of ' . (\is_string($answeredFor) === true ? $answeredFor : '?');
            }
        }

        return $strays;
    }

    /**
     * Whether $target can be reached from $root through properties, arrays and what closures are bound to or capture.
     */
    private static function reaches(object $root, object $target): bool
    {
        $seen = [];
        $pending = [$root];

        while ($pending !== []) {
            $value = array_pop($pending);

            if (\is_array($value) === true) {
                array_push($pending, ...array_values($value));

                continue;
            }

            if (\is_object($value) === false || isset($seen[spl_object_id($value)]) === true) {
                continue;
            }

            if ($value === $target) {
                return true;
            }

            $seen[spl_object_id($value)] = true;
            array_push($pending, ...self::reachableFrom($value));
        }

        return false;
    }

    /**
     * @return list<mixed> what the object holds: its properties, or what a closure is bound to and captures
     */
    private static function reachableFrom(object $object): array
    {
        if ($object instanceof Closure) {
            $reflectionFunction = new ReflectionFunction($object);

            return [$reflectionFunction->getClosureThis(), ...array_values($reflectionFunction->getStaticVariables())];
        }

        $values = [];

        for ($class = new ReflectionObject($object); $class !== false; $class = $class->getParentClass()) {
            foreach ($class->getProperties() as $reflectionProperty) {
                if ($reflectionProperty->isStatic() === false && $reflectionProperty->isInitialized($object) === true) {
                    $values[] = $reflectionProperty->getValue($object);
                }
            }
        }

        return $values;
    }
}
