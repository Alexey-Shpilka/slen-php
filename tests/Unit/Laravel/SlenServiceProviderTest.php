<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit\Laravel;

use InvalidArgumentException;
use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Slen\Sdk\Observability\NullSpanRecorder;
use Slen\Sdk\Observability\Psr3SpanRecorder;
use Slen\Sdk\Observability\SpanDTO;
use Slen\Sdk\Observability\SpanRecorderInterface;
use Slen\Sdk\SlenConfig;
use Slen\Sdk\SlenSdk;
use Slen\Sdk\Tests\Unit\Support\LaravelApplication;
use Slen\Sdk\Tests\Unit\Support\RecordingLogger;

/**
 * The provider on a bare container, as a Laravel application gives it the keys of config/slen-sdk.php.
 */
final class SlenServiceProviderTest extends TestCase
{
    /** What config/slen-sdk.php holds with nothing set but the key. */
    private const array DEFAULTS = [
        'api_key' => 'sk_live_provider',
        'timeout' => 65.0,
        'connect_timeout' => 5.0,
        'retries' => 2,
        'log_channel' => null,
        'spans' => 'null',
        'span_level' => 'info',
        'webhook_secret' => null,
        'webhook_tolerance' => 300,
    ];

    public function testEveryKeyFeedsItsParameterOfSlenConfig(): void
    {
        $slenConfig = self::slenConfig([
            'api_key' => 'sk_live_a',
            'timeout' => '0.5',
            'connect_timeout' => '1.5',
            'retries' => '0',
            'webhook_secret' => 'whsec_x',
            'webhook_tolerance' => '60',
        ]);

        self::assertSame(
            ['sk_live_a', 0.5, 1.5, 0, 'whsec_x', 60],
            [
                $slenConfig->apiKey(),
                $slenConfig->timeout,
                $slenConfig->connectTimeout,
                $slenConfig->retries,
                $slenConfig->webhookSecret(),
                $slenConfig->webhookToleranceSeconds,
            ],
        );
    }

    public function testTheDefaultsOfTheFileAreTheDefaultsOfTheSdk(): void
    {
        self::assertEquals(new SlenConfig('sk_live_provider'), self::slenConfig([]));
    }

    public function testTheSdkTalksToTheHostedApiUnlessATestOrALocalStackSetsAnotherAddress(): void
    {
        self::assertSame(SlenConfig::DEFAULT_BASE_URL, self::slenConfig([])->baseUrl);
        self::assertSame('https://api.test', self::slenConfig(['base_url' => 'https://api.test'])->baseUrl);
    }

    #[DataProvider('noAddressProvider')]
    public function testABlankAddressIsTheHostedApi(?string $baseUrl): void
    {
        self::assertSame(SlenConfig::DEFAULT_BASE_URL, self::slenConfig(['base_url' => $baseUrl])->baseUrl);
    }

    /**
     * @return Iterator<string, array{string|null}>
     */
    public static function noAddressProvider(): Iterator
    {
        yield 'null' => [null];
        yield 'empty' => [''];
    }

    #[DataProvider('noTimeProvider')]
    public function testATimeoutThatWouldMeanForeverIsRefusedWhenTheSdkIsBuilt(string $timeout): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('timeout must be a positive number of seconds');

        self::slenConfig(['timeout' => $timeout]);
    }

    /**
     * @return Iterator<string, array{string}>
     */
    public static function noTimeProvider(): Iterator
    {
        yield 'zero' => ['0'];
        yield 'empty' => [''];
        yield 'a word' => ['thirty'];
    }

    public function testWithNoChannelTheJournalGoesToTheLoggerOfTheApplicationIfThereIsOne(): void
    {
        $recordingLogger = new RecordingLogger();
        $application = LaravelApplication::withTheProvider(self::DEFAULTS);
        $application->instance(LoggerInterface::class, $recordingLogger);

        self::assertSame($recordingLogger, self::make($application, SlenConfig::class)->logger);
        self::assertInstanceOf(NullLogger::class, self::slenConfig([])->logger);
    }

    public function testANamedChannelIsAskedOfTheLogManager(): void
    {
        $recordingLogger = new RecordingLogger();
        $application = LaravelApplication::withTheProvider(['log_channel' => 'slen'] + self::DEFAULTS);
        $application->instance('log', new class ($recordingLogger) {
            /** @var list<string> */
            public array $asked = [];

            public function __construct(private readonly LoggerInterface $logger)
            {
            }

            public function channel(string $name): LoggerInterface
            {
                $this->asked[] = $name;

                return $this->logger;
            }
        });

        self::assertSame($recordingLogger, self::make($application, SlenConfig::class)->logger);
    }

    public function testANamedChannelWithoutALogManagerIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('log_channel names "slen"');

        self::slenConfig(['log_channel' => 'slen']);
    }

    public function testSpansOfLogAreWrittenToTheLoggerOfTheJournalAtTheirLevel(): void
    {
        $recordingLogger = new RecordingLogger();
        $application = LaravelApplication::withTheProvider(['spans' => 'log', 'span_level' => 'debug'] + self::DEFAULTS);
        $application->instance(LoggerInterface::class, $recordingLogger);

        $spanRecorder = self::make($application, SlenConfig::class)->spanRecorder;
        $spanRecorder->record(new SpanDTO('operations.execute', 'ok', 'completed', 3));

        self::assertInstanceOf(Psr3SpanRecorder::class, $spanRecorder);
        self::assertSame($spanRecorder, self::make($application, SpanRecorderInterface::class));
        self::assertSame(['debug slen.span'], $recordingLogger->events());
    }

    public function testSpansAreOffUnlessAskedFor(): void
    {
        self::assertInstanceOf(NullSpanRecorder::class, self::slenConfig(['spans' => 'otlp'])->spanRecorder);
    }

    public function testTheSdkIsOneForTheApplicationBuiltFromItsConfig(): void
    {
        $application = LaravelApplication::withTheProvider(self::DEFAULTS);

        $slenSdk = self::make($application, SlenSdk::class);

        self::assertSame($slenSdk, self::make($application, SlenSdk::class));
        self::assertSame(self::make($application, SlenConfig::class), $slenSdk->config());
    }

    /**
     * @param array<string, mixed> $settings on top of the defaults
     */
    private static function slenConfig(array $settings): SlenConfig
    {
        return self::make(LaravelApplication::withTheProvider($settings + self::DEFAULTS), SlenConfig::class);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $abstract
     *
     * @return T
     */
    private static function make(LaravelApplication $laravelApplication, string $abstract): object
    {
        $made = $laravelApplication->make($abstract);
        self::assertInstanceOf($abstract, $made);

        return $made;
    }
}
