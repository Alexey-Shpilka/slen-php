<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit\Shared\Http\Transport;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slen\Sdk\Observability\NullSpanRecorder;
use Slen\Sdk\RequestOptions;
use Slen\Sdk\Testing\FakeHttpClient;
use Slen\Sdk\Tests\Unit\Support\Answers;
use Slen\Sdk\Tests\Unit\Support\CustomerAnswers;
use Slen\Sdk\Tests\Unit\Support\RecordingLogger;

final class Psr18TransportTest extends TestCase
{
    public function testAClientThatCannotBeGivenTheTimeoutOfACallSaysSoOnceForThatCall(): void
    {
        $fakeHttpClient = (new FakeHttpClient())
            ->pushJson(503, Answers::refusal('service_unavailable'), ['Retry-After' => '0'])
            ->pushJson(200, ['data' => CustomerAnswers::customer()]);
        $logger = new RecordingLogger();

        Answers::sdk($fakeHttpClient, new NullSpanRecorder(), $logger, retries: 1)->customers()->get('user_8421', new RequestOptions(timeout: 3.5));

        self::assertCount(2, $fakeHttpClient->requests());
        self::assertSame(['warning slen.config.timeout_not_applied'], array_values(array_filter(
            $logger->events(),
            static function (string $event): bool {
                return str_ends_with($event, 'timeout_not_applied');
            },
        )));
        self::assertSame(
            ['method' => 'GET', 'route' => '/v1/customers/{externalUserId}', 'client' => FakeHttpClient::class],
            $logger->context('slen.config.timeout_not_applied'),
        );
    }

    public function testACallThatAsksForNoTimeoutWarnsOfNothing(): void
    {
        $fakeHttpClient = (new FakeHttpClient())->pushJson(200, ['data' => CustomerAnswers::customer()]);
        $logger = new RecordingLogger();

        Answers::sdk($fakeHttpClient, new NullSpanRecorder(), $logger)->customers()->get('user_8421', new RequestOptions(retries: 0));

        self::assertSame(['debug slen.request', 'debug slen.response'], $logger->events());
    }

    public function testTheCallIsSentWithTheClientsOwnTimeouts(): void
    {
        $fakeHttpClient = (new FakeHttpClient())->pushJson(200, ['data' => CustomerAnswers::customer()]);

        $customer = Answers::sdk($fakeHttpClient, new NullSpanRecorder(), new NullLogger())->customers()->get('user_8421', new RequestOptions(timeout: 3.5));

        self::assertSame('user_8421', $customer->externalUserId);
        self::assertCount(1, $fakeHttpClient->requests());
    }
}
