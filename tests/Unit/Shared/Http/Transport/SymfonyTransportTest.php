<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit\Shared\Http\Transport;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Slen\Sdk\RequestOptions;
use Slen\Sdk\SlenConfig;
use Slen\Sdk\SlenSdk;
use Slen\Sdk\Tests\Unit\Support\Answers;
use Slen\Sdk\Tests\Unit\Support\CustomerAnswers;
use Slen\Sdk\Tests\Unit\Support\RecordingLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class SymfonyTransportTest extends TestCase
{
    /** @var list<array<mixed>> */
    private array $options = [];

    public function testTheTimeoutOfACallIsTheTimeoutAndTheLongestDurationOfThatCall(): void
    {
        $logger = new RecordingLogger();
        $psr17Factory = new Psr17Factory();
        $mockHttpClient = new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
            $this->options[] = $options;

            return new MockResponse(json_encode(['data' => CustomerAnswers::customer()], JSON_THROW_ON_ERROR), [
                'response_headers' => ['Content-Type' => 'application/json'],
            ]);
        });
        $psr18Client = new Psr18Client($mockHttpClient->withOptions(['timeout' => 9.0, 'max_duration' => 9.0]), $psr17Factory, $psr17Factory);
        $slenSdk = SlenSdk::create(new SlenConfig(Answers::KEY, retries: 0, logger: $logger, httpClient: $psr18Client));

        $slenSdk->customers()->get('user_8421', new RequestOptions(timeout: 3.5));
        $slenSdk->customers()->get('user_8421');

        self::assertSame([3.5, 3.5], [$this->options[0]['timeout'] ?? null, $this->options[0]['max_duration'] ?? null]);
        self::assertSame([9.0, 9.0], [$this->options[1]['timeout'] ?? null, $this->options[1]['max_duration'] ?? null]);
        self::assertNotContains('warning slen.config.timeout_not_applied', $logger->events());
    }
}
