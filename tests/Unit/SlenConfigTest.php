<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit;

use InvalidArgumentException;
use Iterator;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Slen\Sdk\Observability\NullSpanRecorder;
use Slen\Sdk\Observability\Psr3SpanRecorder;
use Slen\Sdk\SlenConfig;
use Slen\Sdk\Tests\Unit\Support\QueuedHttpClient;
use Stringable;

final class SlenConfigTest extends TestCase
{
    public function testConstructHasTheDefaultsOfTheSpec(): void
    {
        $config = new SlenConfig('sk_test');

        self::assertSame('sk_test', $config->apiKey());
        self::assertSame('https://api.slen.ai', $config->baseUrl);
        self::assertSame(65.0, $config->timeout);
        self::assertSame(5.0, $config->connectTimeout);
        self::assertSame(2, $config->retries);
        self::assertInstanceOf(NullLogger::class, $config->logger);
        self::assertInstanceOf(NullSpanRecorder::class, $config->spanRecorder);
        self::assertNull($config->httpClient);
        self::assertNull($config->requestFactory);
        self::assertNull($config->streamFactory);
        self::assertNull($config->webhookSecret());
        self::assertSame(300, $config->webhookToleranceSeconds);
    }

    /**
     * @param array{apiKey: string, timeout?: float, connectTimeout?: float, retries?: int, webhookToleranceSeconds?: int} $arguments
     */
    #[DataProvider('invalidProvider')]
    public function testConstructRefusesAValueOutOfItsRange(array $arguments, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new SlenConfig(...$arguments);
    }

    /**
     * @return Iterator<string, array{array{apiKey: string, timeout?: float, connectTimeout?: float, retries?: int, webhookToleranceSeconds?: int}, string}>
     */
    public static function invalidProvider(): Iterator
    {
        yield 'empty key' => [['apiKey' => ''], 'API key is required'];
        yield 'blank key' => [['apiKey' => '  '], 'API key is required'];
        yield 'zero timeout' => [['apiKey' => 'k', 'timeout' => 0.0], 'timeout must be a positive'];
        yield 'negative timeout' => [['apiKey' => 'k', 'timeout' => -1.0], 'timeout must be a positive'];
        yield 'infinite timeout' => [['apiKey' => 'k', 'timeout' => INF], 'timeout must be a positive'];
        yield 'zero connect timeout' => [['apiKey' => 'k', 'connectTimeout' => 0.0], 'connectTimeout must be a positive'];
        yield 'negative retries' => [['apiKey' => 'k', 'retries' => -1], 'retries must be zero or more'];
        yield 'zero webhook tolerance' => [['apiKey' => 'k', 'webhookToleranceSeconds' => 0], 'webhookToleranceSeconds'];
    }

    public function testConstructTakesAnEmptyWebhookSecretForNone(): void
    {
        self::assertNull((new SlenConfig('k', webhookSecret: ''))->webhookSecret());
        self::assertNull((new SlenConfig('k', webhookSecret: '  '))->webhookSecret());
    }

    public function testConstructTakesALocalAddressAndZeroRetries(): void
    {
        $config = new SlenConfig('k', baseUrl: 'https://api.test', retries: 0, webhookToleranceSeconds: 1);

        self::assertSame('https://api.test', $config->baseUrl);
        self::assertSame(0, $config->retries);
    }

    public function testWithMethodsChangeOneThingAndKeepTheRest(): void
    {
        $config = new SlenConfig('sk_test', baseUrl: 'https://api.test', timeout: 9.0, retries: 1, webhookSecret: 'whsec_1');
        $logger = new NullLogger();
        $recorder = new Psr3SpanRecorder($logger);
        $client = new QueuedHttpClient();
        $psr17Factory = new Psr17Factory();

        $changed = $config->withLogger($logger)->withSpanRecorder($recorder)->withHttpClient($client, $psr17Factory, $psr17Factory);

        self::assertSame($logger, $changed->logger);
        self::assertSame($recorder, $changed->spanRecorder);
        self::assertSame($client, $changed->httpClient);
        self::assertSame($psr17Factory, $changed->requestFactory);
        self::assertSame($psr17Factory, $changed->streamFactory);
        self::assertSame('sk_test', $changed->apiKey());
        self::assertSame('whsec_1', $changed->webhookSecret());
        self::assertSame('https://api.test', $changed->baseUrl);
        self::assertSame(9.0, $changed->timeout);
        self::assertSame(1, $changed->retries);
        self::assertNotSame($logger, $config->logger);
    }

    public function testTheSecretsStayOutOfDumpsJsonAndTraces(): void
    {
        $config = new SlenConfig('sk_canary', webhookSecret: 'whsec_canary');

        self::assertStringNotContainsString('canary', print_r($config, true));
        self::assertStringNotContainsString('canary', (string) json_encode($config));

        try {
            new SlenConfig('sk_canary', timeout: -1.0, webhookSecret: 'whsec_canary');
            self::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $invalidArgumentException) {
            self::assertStringNotContainsString('canary', $invalidArgumentException->getTraceAsString());
        }
    }

    public function testTheLoggerIsAnyPsr3Logger(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };

        self::assertSame($logger, (new SlenConfig('k', logger: $logger))->logger);
    }
}
