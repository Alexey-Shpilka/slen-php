<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit;

use Iterator;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Slen\Sdk\Exceptions\AuthenticationException;
use Slen\Sdk\RequestOptions;
use Slen\Sdk\Shared\Http\Client\HttpContext;
use Slen\Sdk\Shared\Http\Transport\GuzzleTransport;
use Slen\Sdk\Shared\SdkFactory;
use Slen\Sdk\SlenConfig;
use Slen\Sdk\SlenSdk;
use Slen\Sdk\Testing\RecordingSpanRecorder;
use Slen\Sdk\Tests\Unit\Support\QueuedHttpClient;
use Slen\Sdk\Tests\Unit\Support\RecordingLogger;

final class SlenSdkTest extends TestCase
{
    private const array HEALTH = [
        'app' => 'slen',
        'release' => null,
        'server' => 'frankenphp',
        'mysql' => 'ok',
        'redis' => 'ok',
        'queue' => 'ok',
        'broadcast' => null,
        'opcache_preloaded_files' => null,
        'opcache_cached_scripts' => null,
    ];

    public function testCreateTouchesNoNetwork(): void
    {
        $http = new QueuedHttpClient();

        $sdk = SlenSdk::create($this->config($http));

        self::assertSame([], $http->requests);
        self::assertSame('sk_test_1', $sdk->config()->apiKey());
    }

    public function testHealthAsksTheDefaultAddressSignedAndNamed(): void
    {
        $http = (new QueuedHttpClient())->pushJson(200, self::HEALTH);

        $health = SlenSdk::create($this->config($http))->health();
        $request = $http->lastRequest();

        self::assertSame('slen', $health->app);
        self::assertSame('GET', $request->getMethod());
        self::assertSame('https://api.slen.ai/api/health', (string) $request->getUri());
        self::assertSame('Bearer sk_test_1', $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertStringStartsWith('slen-sdk-php/', $request->getHeaderLine('User-Agent'));
    }

    /**
     * Keys are sl_live_...; one issued earlier as sg_live_... keeps working. The SDK checks no prefix: the server does.
     */
    #[DataProvider('keyProvider')]
    public function testAKeyGoesOutAsItIsWhateverItsPrefix(string $key): void
    {
        $http = (new QueuedHttpClient())->pushJson(200, self::HEALTH);
        $psr17Factory = new Psr17Factory();

        SlenSdk::create((new SlenConfig($key))->withHttpClient($http, $psr17Factory, $psr17Factory))->health();

        self::assertSame('Bearer ' . $key, $http->lastRequest()->getHeaderLine('Authorization'));
    }

    /**
     * @return Iterator<string, array{string}>
     */
    public static function keyProvider(): Iterator
    {
        yield 'a key of today' => ['sl_live_7f3a'];
        yield 'a key issued earlier' => ['sg_live_7f3a'];
    }

    public function testHealthGoesToTheAddressOfTheConfig(): void
    {
        $http = (new QueuedHttpClient())->pushJson(200, self::HEALTH);
        $psr17Factory = new Psr17Factory();
        $config = (new SlenConfig('sk_test_1', baseUrl: 'https://api.test/'))->withHttpClient($http, $psr17Factory, $psr17Factory);

        SlenSdk::create($config)->health();

        self::assertSame('https://api.test/api/health', (string) $http->lastRequest()->getUri());
    }

    public function testHealthIsRepeatedAfterA503(): void
    {
        $http = (new QueuedHttpClient())
            ->pushJson(503, ['error' => ['code' => 'service_unavailable', 'message' => 'busy']], ['Retry-After' => '0'])
            ->pushJson(200, self::HEALTH);

        SlenSdk::create($this->config($http))->health();

        self::assertCount(2, $http->requests);
    }

    public function testHealthThrowsARefusalAsItsType(): void
    {
        $http = (new QueuedHttpClient())->pushJson(401, ['error' => ['code' => 'invalid_api_key', 'message' => 'no']]);

        $this->expectException(AuthenticationException::class);

        SlenSdk::create($this->config($http))->health();
    }

    public function testWithLoggerIsACopyWhoseJournalGoesElsewhereOverTheSameClient(): void
    {
        $http = (new QueuedHttpClient())->pushJson(200, self::HEALTH)->pushJson(200, self::HEALTH);
        $before = new RecordingLogger();
        $after = new RecordingLogger();
        $psr17Factory = new Psr17Factory();
        $sdk = SlenSdk::create((new SlenConfig('sk_test_1', logger: $before))->withHttpClient($http, $psr17Factory, $psr17Factory));

        $copy = $sdk->withLogger($after);
        $sdk->health();
        $copy->health(new RequestOptions(retries: 0));

        self::assertNotSame($sdk, $copy);
        self::assertSame($before, $sdk->config()->logger);
        self::assertSame($after, $copy->config()->logger);
        self::assertSame($sdk->config()->apiKey(), $copy->config()->apiKey());
        self::assertSame(['debug slen.request', 'debug slen.response'], $before->events());
        self::assertSame(['debug slen.request', 'debug slen.response'], $after->events());
        self::assertCount(2, $http->requests, 'both SDKs send through the one client');
    }

    public function testACopyKeepsTheSpanRecorderAndHandsOutModulesOfItsOwn(): void
    {
        $spans = new RecordingSpanRecorder();
        $psr17Factory = new Psr17Factory();
        $sdk = SlenSdk::create((new SlenConfig('sk_test_1', spanRecorder: $spans))->withHttpClient(new QueuedHttpClient(), $psr17Factory, $psr17Factory));

        $copy = $sdk->withLogger(new RecordingLogger());

        self::assertSame($spans, $copy->config()->spanRecorder);
        self::assertSame($sdk->operations(), $sdk->operations());
        self::assertNotSame($sdk->operations(), $copy->operations());
        self::assertNotSame($sdk->webhooks(), $copy->webhooks());
    }

    public function testACopyOfAnSdkThatBuiltItsOwnClientSendsThroughThatClient(): void
    {
        $sdk = SlenSdk::create(new SlenConfig('sk_test_1'));

        $copy = $sdk->withLogger(new RecordingLogger());

        self::assertInstanceOf(GuzzleTransport::class, self::httpContextOf($sdk)->transport);
        self::assertSame(self::httpContextOf($sdk), self::httpContextOf($copy));
    }

    private static function httpContextOf(SlenSdk $sdk): HttpContext
    {
        $sdkFactory = (new ReflectionProperty(SlenSdk::class, 'sdkFactory'))->getValue($sdk);
        self::assertInstanceOf(SdkFactory::class, $sdkFactory);

        $httpContext = (new ReflectionProperty(SdkFactory::class, 'httpContext'))->getValue($sdkFactory);
        self::assertInstanceOf(HttpContext::class, $httpContext);

        return $httpContext;
    }

    private function config(QueuedHttpClient $http): SlenConfig
    {
        $psr17Factory = new Psr17Factory();

        return (new SlenConfig('sk_test_1'))->withHttpClient($http, $psr17Factory, $psr17Factory);
    }
}
