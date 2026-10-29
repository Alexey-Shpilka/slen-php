<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit;

use Closure;
use Iterator;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slen\Sdk\Exceptions\SlenException;
use Slen\Sdk\Modules\Customers\Options\CustomerListOptions;
use Slen\Sdk\Modules\Customers\Options\EntryListOptions;
use Slen\Sdk\Modules\Customers\Options\MovementOptions;
use Slen\Sdk\SlenConfig;
use Slen\Sdk\SlenSdk;
use Slen\Sdk\Tests\Unit\Support\CustomerAnswers;
use Slen\Sdk\Tests\Unit\Support\NetworkFailure;
use Slen\Sdk\Tests\Unit\Support\QueuedHttpClient;
use Slen\Sdk\Tests\Unit\Support\RecordingLogger;

/**
 * The journal of a whole call through SlenSdk: exactly the events of the table, and none of the values
 * the SDK must never write. Every forbidden place carries a value with "canary" in it.
 */
final class SlenSdkJournalTest extends TestCase
{
    private const string ROUTE = '/v1/customers/{externalUserId}/charge';

    /**
     * @param Closure(QueuedHttpClient): QueuedHttpClient $answers
     * @param list<string>                    $events
     */
    #[DataProvider('pathProvider')]
    public function testACallWritesTheEventsOfItsPathAndNoValue(Closure $answers, int $retries, array $events, ?string $thrown): void
    {
        $http = new QueuedHttpClient();
        $answers($http);
        $logger = new RecordingLogger();
        $caught = null;

        try {
            $this->charge($http, $logger, $retries);
        } catch (SlenException $slenException) {
            $caught = $slenException::type();
        }

        self::assertSame($events, $logger->events());
        self::assertSame($thrown, $caught);
        self::assertStringNotContainsStringIgnoringCase('canary', (string) json_encode($logger->records));
        self::assertStringNotContainsString('Bearer', (string) json_encode($logger->records));
    }

    /**
     * The same calls on a copy made by withLogger(): every event in the copy's logger, none in the original's.
     *
     * @param Closure(QueuedHttpClient): QueuedHttpClient $answers
     * @param list<string>                    $events
     */
    #[DataProvider('pathProvider')]
    public function testACallOfACopyWithAnotherLoggerWritesTheSameEventsThereAndNoValue(Closure $answers, int $retries, array $events, ?string $thrown): void
    {
        $http = new QueuedHttpClient();
        $answers($http);
        $original = new RecordingLogger();
        $logger = new RecordingLogger();
        $caught = null;

        try {
            $this->chargeOn(SlenSdk::create($this->config($http, $original, $retries))->withLogger($logger));
        } catch (SlenException $slenException) {
            $caught = $slenException::type();
        }

        self::assertSame($events, $logger->events());
        self::assertSame([], $original->records);
        self::assertSame($thrown, $caught);
        self::assertStringNotContainsStringIgnoringCase('canary', (string) json_encode($logger->records));
        self::assertStringNotContainsString('Bearer', (string) json_encode($logger->records));
    }

    public function testNoValueOfTheQueryOrThePathIsWrittenByAList(): void
    {
        $http = (new QueuedHttpClient())
            ->pushJson(200, CustomerAnswers::page([]))
            ->pushJson(200, CustomerAnswers::page([]));
        $logger = new RecordingLogger();
        $sdk = SlenSdk::create($this->config($http, $logger, 0));

        $sdk->customers()->list(new CustomerListOptions(search: 'canary-query', creditType: 'canary-type', lowBalance: '7'));
        $sdk->customers()->entries('canary.person@example.com', new EntryListOptions(cursor: 'canary-cursor', creditType: 'canary-type'));

        self::assertSame(['debug slen.request', 'debug slen.response', 'debug slen.request', 'debug slen.response'], $logger->events());
        self::assertStringNotContainsStringIgnoringCase('canary', (string) json_encode($logger->records));
    }

    /**
     * @return Iterator<string, array{Closure(QueuedHttpClient): QueuedHttpClient, int, list<string>, ?string}>
     */
    public static function pathProvider(): Iterator
    {
        $output = ['data' => CustomerAnswers::account(['note' => 'canary-output'])];
        $refusal = static function (string $code): array {
            return ['error' => ['code' => $code, 'message' => 'canary-message', 'request_id' => 'r-1']];
        };

        yield 'success' => [
            static function (QueuedHttpClient $http) use ($output): QueuedHttpClient {
                return $http->pushJson(200, $output);
            },
            2,
            ['debug slen.request', 'debug slen.response'],
            null,
        ];
        yield '402' => [
            static function (QueuedHttpClient $http) use ($refusal): QueuedHttpClient {
                return $http->pushJson(402, $refusal('insufficient_credits'));
            },
            2,
            ['debug slen.request', 'info slen.refused'],
            'InsufficientCredits',
        ];
        yield '401' => [
            static function (QueuedHttpClient $http) use ($refusal): QueuedHttpClient {
                return $http->pushJson(401, $refusal('invalid_api_key'));
            },
            2,
            ['debug slen.request', 'error slen.unauthorized'],
            'Authentication',
        ];
        yield 'a retry after 503' => [
            static function (QueuedHttpClient $http) use ($refusal, $output): QueuedHttpClient {
                return $http
                    ->pushJson(503, $refusal('service_unavailable'), ['Retry-After' => '0'])
                    ->pushJson(200, $output);
            },
            2,
            ['debug slen.request', 'warning slen.retry', 'debug slen.request', 'debug slen.response'],
            null,
        ];
        yield 'no answer' => [
            static function (QueuedHttpClient $http): QueuedHttpClient {
                return $http->pushException(new NetworkFailure('canary.person@example.com refused'));
            },
            0,
            ['debug slen.request', 'error slen.failed'],
            'Connection',
        ];
        yield 'an unreadable answer' => [
            static function (QueuedHttpClient $http): QueuedHttpClient {
                return $http->pushRaw(200, '<html>canary-page</html>');
            },
            2,
            ['debug slen.request', 'error slen.failed'],
            'InvalidResponse',
        ];
        yield 'a 429 that asks for too long' => [
            static function (QueuedHttpClient $http) use ($refusal): QueuedHttpClient {
                return $http->pushJson(429, $refusal('rate_limit_exceeded'), ['Retry-After' => '60']);
            },
            2,
            ['debug slen.request', 'info slen.refused'],
            'RateLimit',
        ];
        yield 'a 500 of the flow' => [
            static function (QueuedHttpClient $http) use ($refusal): QueuedHttpClient {
                return $http->pushJson(500, $refusal('flow_runtime_error'));
            },
            2,
            ['debug slen.request', 'error slen.failed'],
            'FlowRuntime',
        ];
    }

    public function testTheEventsCarryTheFieldsOfTheTable(): void
    {
        $http = (new QueuedHttpClient())
            ->pushJson(503, ['error' => ['code' => 'service_unavailable', 'message' => 'busy']], ['Retry-After' => '0'])
            ->pushJson(402, ['error' => ['code' => 'insufficient_credits', 'message' => 'no']], ['X-Request-Id' => 'r-9']);
        $logger = new RecordingLogger();

        try {
            $this->charge($http, $logger, 2);
            self::fail('Expected a refusal');
        } catch (SlenException) {
            $where = ['method' => 'POST', 'route' => self::ROUTE];

            self::assertSame([...$where, 'attempt' => 1], $logger->records[0]['context']);
            self::assertSame([...$where, 'attempt' => 1, 'delay_ms' => 0, 'reason' => 'unavailable', 'status' => 503], $logger->context('slen.retry'));
            self::assertSame([...$where, 'attempt' => 2], $logger->records[2]['context']);
            self::assertSame([...$where, 'status' => 402, 'error_code' => 'insufficient_credits', 'request_id' => 'r-9'], $logger->context('slen.refused'));
        }
    }

    public function testTheFailureOfAnUnreadableAnswerNamesItsAttempt(): void
    {
        $http = (new QueuedHttpClient())
            ->pushJson(503, [], ['Retry-After' => '0'])
            ->pushRaw(200, 'not json', ['X-Request-Id' => 'r-3']);
        $logger = new RecordingLogger();

        try {
            $this->charge($http, $logger, 2);
            self::fail('Expected InvalidResponseException');
        } catch (SlenException) {
            self::assertSame(
                [
                    'method' => 'POST',
                    'route' => self::ROUTE,
                    'status' => 200,
                    'error_code' => 'invalid_response',
                    'error_type' => 'InvalidResponse',
                    'request_id' => 'r-3',
                    'attempts' => 2,
                ],
                $logger->context('slen.failed'),
            );
        }
    }

    public function testTheResponseTimesTheWholeCall(): void
    {
        $http = (new QueuedHttpClient())->pushJson(200, ['data' => CustomerAnswers::account()], ['X-Request-Id' => 'r-5']);
        $logger = new RecordingLogger();

        $this->charge($http, $logger, 2);
        $context = $logger->context('slen.response');

        self::assertSame(['method', 'route', 'status', 'duration_ms', 'request_id'], array_keys($context));
        self::assertIsInt($context['duration_ms']);
        self::assertGreaterThanOrEqual(0, $context['duration_ms']);
        self::assertSame('r-5', $context['request_id']);
    }

    public function testWithLoggerSendsTheJournalOfTheCopyToItsLoggerAndLeavesTheOriginalAlone(): void
    {
        $account = ['data' => CustomerAnswers::account()];
        $http = (new QueuedHttpClient())->pushJson(200, $account)->pushJson(200, $account)->pushJson(200, $account);
        $first = new RecordingLogger();
        $second = new RecordingLogger();
        $sdk = SlenSdk::create($this->config($http, $first, 2));
        $copy = $sdk->withLogger($second);

        $this->chargeOn($sdk);
        $this->chargeOn($copy);
        $this->chargeOn($sdk);

        self::assertCount(4, $first->records);
        self::assertCount(2, $second->records);
        self::assertSame($first, $sdk->config()->logger);
        self::assertSame($second, $copy->config()->logger);
        self::assertCount(3, $http->requests);
    }

    private function charge(QueuedHttpClient $http, RecordingLogger $logger, int $retries): void
    {
        $this->chargeOn(SlenSdk::create($this->config($http, $logger, $retries)));
    }

    private function chargeOn(SlenSdk $sdk): void
    {
        $sdk->customers()->charge(
            'canary.person@example.com',
            '5.0000',
            new MovementOptions('canary-credit-type', 'canary-body', 'idem-canary-key'),
        );
    }

    private function config(QueuedHttpClient $http, RecordingLogger $logger, int $retries): SlenConfig
    {
        $psr17Factory = new Psr17Factory();

        return (new SlenConfig('sk_canary_key', retries: $retries, logger: $logger, webhookSecret: 'whsec_canary'))
            ->withHttpClient($http, $psr17Factory, $psr17Factory);
    }
}
