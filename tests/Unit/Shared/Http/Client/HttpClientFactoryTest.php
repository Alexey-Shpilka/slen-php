<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit\Shared\Http\Client;

use Composer\Autoload\ClassLoader;
use GuzzleHttp\Client as GuzzleClient;
use Http\Discovery\Psr18ClientDiscovery;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionProperty;
use Slen\Sdk\Exceptions\ConnectionException;
use Slen\Sdk\Shared\Http\Client\HttpClientFactory;
use Slen\Sdk\Shared\Http\Transport\GuzzleTransport;
use Slen\Sdk\Shared\Http\Transport\Psr18Transport;
use Slen\Sdk\Shared\Http\Transport\SymfonyTransport;
use Slen\Sdk\Shared\Logging\Journal;
use Slen\Sdk\SlenConfig;
use Slen\Sdk\SlenSdk;
use Slen\Sdk\Tests\Unit\Support\QueuedHttpClient;
use Slen\Sdk\Tests\Unit\Support\RecordingLogger;
use Symfony\Component\HttpClient\Psr18Client;

final class HttpClientFactoryTest extends TestCase
{
    public function testCreateSendsThroughTheClientOfTheConfigAsItIs(): void
    {
        $client = (new QueuedHttpClient())->pushJson(200, []);
        $psr17Factory = new Psr17Factory();
        $config = (new SlenConfig('sk_test'))->withHttpClient($client, $psr17Factory, $psr17Factory);

        $context = (new HttpClientFactory())->create($config, new Journal(new NullLogger()));
        $context->transport->send($psr17Factory->createRequest('GET', 'https://api.test/api/health'), null);

        self::assertInstanceOf(Psr18Transport::class, $context->transport);
        self::assertCount(1, $client->requests);
        self::assertSame($psr17Factory, $context->requestFactory);
        self::assertSame($psr17Factory, $context->streamFactory);
    }

    public function testAGuzzleOrSymfonyClientOfTheConfigIsGivenTheTimeoutOfACall(): void
    {
        $psr17Factory = new Psr17Factory();
        $journal = new Journal(new NullLogger());
        $guzzle = (new SlenConfig('sk_test'))->withHttpClient(new GuzzleClient(), $psr17Factory, $psr17Factory);
        $symfony = (new SlenConfig('sk_test'))->withHttpClient(new Psr18Client(), $psr17Factory, $psr17Factory);

        self::assertInstanceOf(GuzzleTransport::class, (new HttpClientFactory())->create($guzzle, $journal)->transport);
        self::assertInstanceOf(SymfonyTransport::class, (new HttpClientFactory())->create($symfony, $journal)->transport);
    }

    public function testASymfonyClientWithoutOptionsPerCallIsSentToAsAnyPsr18Client(): void
    {
        $psr17Factory = new Psr17Factory();
        $config = (new SlenConfig('sk_test'))->withHttpClient(new Psr18Client(), $psr17Factory, $psr17Factory);

        $transport = (new HttpClientFactory(null, self::withoutWithOptions(...)))->create($config, new Journal(new NullLogger()))->transport;

        self::assertInstanceOf(Psr18Transport::class, $transport);
        self::assertSame(Psr18Client::class, $transport->clientClass());
    }

    public function testASymfonyClientTheSdkBuildsWithoutOptionsPerCallKeepsTheTimeoutsOfTheConfigAndWarnsOfNothing(): void
    {
        $logger = new RecordingLogger();
        $factory = new HttpClientFactory(static function (string $class): bool {
            return $class !== GuzzleClient::class && class_exists($class);
        }, self::withoutWithOptions(...));

        $transport = $factory->create(new SlenConfig('sk_test'), new Journal($logger))->transport;

        self::assertInstanceOf(Psr18Transport::class, $transport);
        self::assertSame(Psr18Client::class, $transport->clientClass());
        self::assertSame([], $logger->records);
    }

    public function testCreateGivesGuzzleTheTimeoutsOfTheConfig(): void
    {
        $transport = (new HttpClientFactory())->create(new SlenConfig('sk_test', timeout: 12.5, connectTimeout: 1.5), new Journal(new NullLogger()))->transport;
        $client = (new ReflectionProperty(GuzzleTransport::class, 'client'))->getValue($transport);

        self::assertInstanceOf(GuzzleClient::class, $client);
        self::assertSame(12.5, $client->getConfig('timeout'));
        self::assertSame(1.5, $client->getConfig('connect_timeout'));
        self::assertFalse($client->getConfig('http_errors'));
    }

    public function testCreateBuildsTheSymfonyClientWhenGuzzleIsNotThere(): void
    {
        $factory = new HttpClientFactory(static function (string $class): bool {
            return $class !== GuzzleClient::class && class_exists($class);
        });

        self::assertInstanceOf(SymfonyTransport::class, $factory->create(new SlenConfig('sk_test'), new Journal(new NullLogger()))->transport);
    }

    public function testCreateDiscoversAClientWhenItKnowsNone(): void
    {
        $factory = new HttpClientFactory(static function (string $class): bool {
            return false;
        });
        $logger = new RecordingLogger();

        $transport = $factory->create(new SlenConfig('sk_test'), new Journal($logger))->transport;

        self::assertInstanceOf(Psr18Transport::class, $transport);
        self::assertSame(Psr18ClientDiscovery::find()::class, $transport->clientClass());
        self::assertSame(['warning slen.config.timeout_not_applied'], $logger->events());
        self::assertSame(['client' => $transport->clientClass()], $logger->context('slen.config.timeout_not_applied'));
    }

    public function testCreateWarnsOfNothingWhenItSetTheTimeoutsOrWasGivenTheClient(): void
    {
        $logger = new RecordingLogger();
        $psr17Factory = new Psr17Factory();

        (new HttpClientFactory())->create(new SlenConfig('sk_test'), new Journal($logger));
        (new HttpClientFactory(static function (string $class): bool {
            return false;
        }))->create(
            (new SlenConfig('sk_test'))->withHttpClient(new QueuedHttpClient(), $psr17Factory, $psr17Factory),
            new Journal($logger),
        );

        self::assertSame([], $logger->records);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheSdkWorksWithoutGuzzleInstalled(): void
    {
        self::hideGuzzle();

        $sdk = SlenSdk::create(new SlenConfig('sk_test', baseUrl: 'http://127.0.0.1:9', retries: 0));

        try {
            $sdk->health();
            self::fail('Expected ConnectionException');
        } catch (ConnectionException $connectionException) {
            $previous = $connectionException->getPrevious();
            self::assertNotNull($previous);
            self::assertStringStartsWith('Symfony\\Component\\HttpClient\\', $previous::class);
        }

        $guzzle = array_filter(get_declared_classes(), static function (string $class): bool {
            return str_starts_with($class, 'GuzzleHttp\\');
        });

        self::assertSame([], array_values($guzzle));
        self::assertFalse(class_exists(GuzzleClient::class));
    }

    /**
     * method_exists as a symfony/http-client before 6.2 answers it.
     */
    private static function withoutWithOptions(string $class, string $method): bool
    {
        return ($class === Psr18Client::class && $method === 'withOptions') === false && method_exists($class, $method);
    }

    private static function hideGuzzle(): void
    {
        $loaders = array_filter(
            spl_autoload_functions(),
            static function (callable $function): bool {
                return \is_array($function) && $function[0] instanceof ClassLoader;
            },
        );

        foreach ($loaders as [$loader]) {
            $loader->unregister();
            spl_autoload_register(static function (string $class) use ($loader): void {
                if (!str_starts_with($class, 'GuzzleHttp\\')) {
                    $loader->loadClass($class);
                }
            });
        }
    }
}
