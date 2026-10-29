<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit\Testing;

use LogicException;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Slen\Sdk\Exceptions\ConnectionException;
use Slen\Sdk\SlenConfig;
use Slen\Sdk\SlenSdk;
use Slen\Sdk\Testing\FakeHttpClient;
use Slen\Sdk\Tests\Unit\Support\NetworkFailure;

final class FakeHttpClientTest extends TestCase
{
    public function testAnswersAreServedInTheOrderTheyWereQueued(): void
    {
        $fakeHttpClient = (new FakeHttpClient())
            ->pushJson(202, ['data' => ['status' => 'queued']], ['Retry-After' => '3'])
            ->pushJson(200, ['data' => ['status' => 'completed', 'cost' => '0.50']]);

        $first = $fakeHttpClient->sendRequest(new Request('POST', 'https://api.slen.ai/api/v1/operations/execute'));
        $second = $fakeHttpClient->sendRequest(new Request('GET', 'https://api.slen.ai/api/v1/executions/7/status'));

        self::assertSame(202, $first->getStatusCode());
        self::assertSame('application/json', $first->getHeaderLine('Content-Type'));
        self::assertSame('3', $first->getHeaderLine('Retry-After'));
        self::assertSame('{"data":{"status":"queued"}}', (string) $first->getBody());
        self::assertSame('{"data":{"status":"completed","cost":"0.50"}}', (string) $second->getBody());
        self::assertSame(['POST', 'GET'], array_map(static function (RequestInterface $request): string {
            return $request->getMethod();
        }, $fakeHttpClient->requests()));
        self::assertSame('/api/v1/executions/7/status', $fakeHttpClient->lastRequest()->getUri()->getPath());
    }

    public function testAQueuedFailureIsThrownForItsRequest(): void
    {
        $networkFailure = new NetworkFailure();
        $fakeHttpClient = (new FakeHttpClient())->pushException($networkFailure);

        try {
            $fakeHttpClient->sendRequest(new Request('GET', 'https://api.slen.ai/api/health'));
            self::fail('the queued failure was not thrown');
        } catch (NetworkFailure $caught) {
            self::assertSame($networkFailure, $caught);
        }

        self::assertCount(1, $fakeHttpClient->requests());
    }

    public function testARequestNobodyQueuedAnAnswerForIsKeptAndRefused(): void
    {
        $fakeHttpClient = (new FakeHttpClient())->pushJson(200, ['data' => []]);
        $fakeHttpClient->sendRequest(new Request('GET', 'https://api.slen.ai/api/health'));

        try {
            $fakeHttpClient->sendRequest(new Request('DELETE', 'https://api.slen.ai/api/v1/customers/a'));
            self::fail('a request with no answer queued went through');
        } catch (LogicException $logicException) {
            self::assertStringContainsString('request #2 (DELETE https://api.slen.ai/api/v1/customers/a)', $logicException->getMessage());
        }

        self::assertSame('DELETE', $fakeHttpClient->lastRequest()->getMethod());
    }

    public function testTheLastRequestOfNoneIsRefused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('FakeHttpClient was sent no request.');

        (new FakeHttpClient())->lastRequest();
    }

    public function testTheSdkRunsOnItWithFactoriesItDiscovers(): void
    {
        $fakeHttpClient = (new FakeHttpClient())
            ->pushJson(200, ['app' => 'slen', 'release' => null, 'server' => 'frankenphp', 'mysql' => 'ok', 'redis' => 'ok'])
            ->pushException(new NetworkFailure());
        $slenSdk = SlenSdk::create((new SlenConfig('sk_test_1', retries: 0))->withHttpClient($fakeHttpClient));

        self::assertSame('frankenphp', $slenSdk->health()->server);
        self::assertSame('Bearer sk_test_1', $fakeHttpClient->lastRequest()->getHeaderLine('Authorization'));

        $this->expectException(ConnectionException::class);

        $slenSdk->health();
    }
}
