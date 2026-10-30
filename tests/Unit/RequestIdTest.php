<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit;

use Closure;
use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slen\Sdk\Exceptions\ConnectionException;
use Slen\Sdk\Exceptions\InvalidRequestException;
use Slen\Sdk\Exceptions\SlenException;
use Slen\Sdk\Exceptions\TimeoutException;
use Slen\Sdk\Modules\Customers\Options\AccountOptions;
use Slen\Sdk\Modules\Customers\Options\BlockOptions;
use Slen\Sdk\Modules\Customers\Options\CustomerListOptions;
use Slen\Sdk\Modules\Customers\Options\EntryListOptions;
use Slen\Sdk\Modules\Customers\Options\MovementOptions;
use Slen\Sdk\Modules\Customers\Options\PriceListOptions;
use Slen\Sdk\Modules\Executions\Options\WaitOptions;
use Slen\Sdk\Modules\Operations\Options\ExecuteOptions;
use Slen\Sdk\RequestOptions;
use Slen\Sdk\SlenSdk;
use Slen\Sdk\Testing\FakeHttpClient;
use Slen\Sdk\Tests\Paths;
use Slen\Sdk\Tests\Unit\Support\Answers;
use Slen\Sdk\Tests\Unit\Support\CustomerAnswers;
use Slen\Sdk\Tests\Unit\Support\NetworkFailure;

/**
 * The id of a request: the one of the answer on every result read from it, and the caller's own on the wire.
 */
final class RequestIdTest extends TestCase
{
    private const string OWN = 'order-42:get';

    /** Calls of the table that answer nothing to carry an id on, or answer from more than one request. */
    private const array WITHOUT_ONE = ['customers.delete', 'customers.entriesAll', 'customers.listAll'];

    /**
     * @return Iterator<string, array{Closure(SlenSdk): object, int, array<string, mixed>}>
     */
    public static function callProvider(): Iterator
    {
        $execution = ['data' => Answers::execution()];
        $account = ['data' => CustomerAnswers::account()];

        yield 'health' => [static function (SlenSdk $s): object {
            return $s->health();
        }, 200, ['app' => 'slen', 'release' => null, 'server' => 'ok', 'mysql' => 'ok', 'redis' => 'ok']];
        yield 'operations.execute' => [static function (SlenSdk $s): object {
            return $s->operations()->execute('support.reply', [], new ExecuteOptions(requestId: self::OWN));
        }, 200, $execution];
        yield 'operations.executeAsync' => [static function (SlenSdk $s): object {
            return $s->operations()->executeAsync('support.reply', []);
        }, 202, $execution];
        yield 'executions.get' => [static function (SlenSdk $s): object {
            return $s->executions()->get('74438200973594700', new RequestOptions(requestId: self::OWN));
        }, 200, ['data' => Answers::status()]];
        yield 'executions.wait' => [static function (SlenSdk $s): object {
            return $s->executions()->wait('74438200973594700', new WaitOptions(5));
        }, 200, ['data' => Answers::status()]];
        yield 'executions.trace' => [static function (SlenSdk $s): object {
            return $s->executions()->trace('74438200973594700', new RequestOptions(requestId: self::OWN));
        }, 200, ['data' => self::trace()]];
        yield 'customers.list' => [static function (SlenSdk $s): object {
            return $s->customers()->list(new CustomerListOptions(requestId: self::OWN));
        }, 200, CustomerAnswers::page([CustomerAnswers::customer()])];
        yield 'customers.get' => [static function (SlenSdk $s): object {
            return $s->customers()->get('user_8421', new RequestOptions(requestId: self::OWN));
        }, 200, ['data' => CustomerAnswers::customer()]];
        yield 'customers.create' => [static function (SlenSdk $s): object {
            return $s->customers()->create('user_8421', new RequestOptions(requestId: self::OWN));
        }, 201, ['data' => CustomerAnswers::customer()]];
        yield 'customers.updateAccount' => [static function (SlenSdk $s): object {
            return $s->customers()->updateAccount('user_8421', 'default', new AccountOptions(requestId: self::OWN));
        }, 200, $account];
        yield 'customers.block' => [static function (SlenSdk $s): object {
            return $s->customers()->block('user_8421', new BlockOptions(requestId: self::OWN));
        }, 200, ['data' => CustomerAnswers::customer(['status' => 'blocked'])]];
        yield 'customers.unblock' => [static function (SlenSdk $s): object {
            return $s->customers()->unblock('user_8421', new RequestOptions(requestId: self::OWN));
        }, 200, ['data' => CustomerAnswers::customer()]];
        yield 'customers.topUp' => [static function (SlenSdk $s): object {
            return $s->customers()->topUp('user_8421', '10', new MovementOptions(requestId: self::OWN));
        }, 200, $account];
        yield 'customers.charge' => [static function (SlenSdk $s): object {
            return $s->customers()->charge('user_8421', '1', new MovementOptions(requestId: self::OWN));
        }, 200, $account];
        yield 'customers.adjust' => [static function (SlenSdk $s): object {
            return $s->customers()->adjust('user_8421', '-1', new MovementOptions(requestId: self::OWN));
        }, 200, $account];
        yield 'customers.entries' => [static function (SlenSdk $s): object {
            return $s->customers()->entries('user_8421', new EntryListOptions(requestId: self::OWN));
        }, 200, CustomerAnswers::page([CustomerAnswers::entry()])];
        yield 'customers.prices' => [static function (SlenSdk $s): object {
            return $s->customers()->prices(new PriceListOptions(requestId: self::OWN));
        }, 200, ['data' => [CustomerAnswers::price()]]];
    }

    /**
     * @param Closure(SlenSdk): object $call
     * @param array<string, mixed>     $answer
     */
    #[DataProvider('callProvider')]
    public function testTheResultOfACallCarriesTheIdOfItsAnswer(Closure $call, int $status, array $answer): void
    {
        $fakeHttpClient = (new FakeHttpClient())->pushJson($status, $answer, ['X-Request-Id' => 'req_7f3a']);

        $result = $call(Answers::sdk($fakeHttpClient));

        self::assertTrue(property_exists($result, 'requestId'), $result::class . ' has no requestId');
        self::assertSame('req_7f3a', $result->requestId);
    }

    public function testEveryCallOfTheTableThatReadsOneAnswerIsDriven(): void
    {
        /** @var array{calls: array<string, mixed>} $table */
        $table = json_decode((string) file_get_contents(Paths::CONTRACT . '/calls.json'), true, 512, JSON_THROW_ON_ERROR);
        $expected = array_values(array_diff(array_keys($table['calls']), self::WITHOUT_ONE));
        $driven = array_keys(iterator_to_array(self::callProvider()));

        sort($expected);
        sort($driven);

        self::assertSame($expected, $driven);
    }

    public function testARowOfAPageAndAnAccountInsideACustomerCarryNone(): void
    {
        $fakeHttpClient = (new FakeHttpClient())
            ->pushJson(200, CustomerAnswers::page([CustomerAnswers::customer()]), ['X-Request-Id' => 'req_page'])
            ->pushJson(200, ['data' => CustomerAnswers::customer()], ['X-Request-Id' => 'req_one']);
        $customers = Answers::sdk($fakeHttpClient)->customers();

        $page = $customers->list();
        $customer = $customers->get('user_8421');

        self::assertSame('req_page', $page->requestId);
        self::assertNull($page->data[0]->requestId);
        self::assertNull($page->data[0]->accounts[0]->requestId);
        self::assertSame('req_one', $customer->requestId);
        self::assertNull($customer->accounts[0]->requestId);
    }

    public function testNoIdComesBackWhenTheAnswerNamesNone(): void
    {
        $fakeHttpClient = (new FakeHttpClient())->pushJson(200, ['data' => CustomerAnswers::customer()]);

        self::assertNull(Answers::sdk($fakeHttpClient)->customers()->get('user_8421')->requestId);
    }

    public function testTheCallersOwnIdGoesOnEveryAttemptOfTheCall(): void
    {
        $fakeHttpClient = (new FakeHttpClient())
            ->pushJson(503, Answers::refusal('service_unavailable'), ['Retry-After' => '0'])
            ->pushJson(503, Answers::refusal('service_unavailable'), ['Retry-After' => '0'])
            ->pushJson(200, ['data' => CustomerAnswers::customer()], ['X-Request-Id' => self::OWN]);

        $customer = Answers::sdk($fakeHttpClient, retries: 2)->customers()->get('user_8421', new RequestOptions(requestId: self::OWN));
        $sent = [];

        foreach ($fakeHttpClient->requests() as $request) {
            $sent[] = $request->getHeaderLine('X-Request-Id');
        }

        self::assertSame([self::OWN, self::OWN, self::OWN], $sent);
        self::assertSame(self::OWN, $customer->requestId);
    }

    /**
     * @return Iterator<string, array{string, class-string<SlenException>}>
     */
    public static function noAnswerProvider(): Iterator
    {
        yield 'a refused connection' => ['Connection refused', ConnectionException::class];
        yield 'a timeout' => ['Operation timed out after 5000 milliseconds', TimeoutException::class];
    }

    /**
     * @param class-string<SlenException> $thrown
     */
    #[DataProvider('noAnswerProvider')]
    public function testACallThatGotNoAnswerThrowsWithTheCallersOwnId(string $failure, string $thrown): void
    {
        $fakeHttpClient = (new FakeHttpClient())->pushException(new NetworkFailure($failure))->pushException(new NetworkFailure($failure));

        try {
            Answers::sdk($fakeHttpClient, retries: 1)->customers()->get('user_8421', new RequestOptions(requestId: self::OWN));
            self::fail('Expected ' . $thrown);
        } catch (SlenException $slenException) {
            self::assertInstanceOf($thrown, $slenException);
            self::assertSame(self::OWN, $slenException->requestId);
        }

        self::assertCount(2, $fakeHttpClient->requests());
    }

    public function testAnAnswerNamesItsOwnIdOverTheCallers(): void
    {
        $fakeHttpClient = (new FakeHttpClient())->pushJson(404, Answers::refusal('customer_not_found'), ['X-Request-Id' => 'req_answer']);

        try {
            Answers::sdk($fakeHttpClient)->customers()->get('user_8421', new RequestOptions(requestId: self::OWN));
            self::fail('Expected a refusal');
        } catch (SlenException $slenException) {
            self::assertSame('req_answer', $slenException->requestId);
        }
    }

    public function testNoIdIsSentUnlessTheCallerGivesOne(): void
    {
        $fakeHttpClient = (new FakeHttpClient())->pushJson(200, ['data' => CustomerAnswers::customer()]);

        Answers::sdk($fakeHttpClient)->customers()->get('user_8421');

        self::assertFalse($fakeHttpClient->lastRequest()->hasHeader('X-Request-Id'));
    }

    /**
     * @return Iterator<string, array{string}>
     */
    public static function badIdProvider(): Iterator
    {
        yield 'shorter than 8' => ['short'];
        yield 'a space and a !' => ['bad id!'];
        yield 'longer than 128' => [str_repeat('a', 129)];
        yield 'a line break at the end' => ["order-42:get\n"];
    }

    #[DataProvider('badIdProvider')]
    public function testAnIdNotOfTheFormTheApiTakesIsRefusedBeforeTheWire(string $requestId): void
    {
        $fakeHttpClient = new FakeHttpClient();

        try {
            Answers::sdk($fakeHttpClient)->customers()->get('user_8421', new RequestOptions(requestId: $requestId));
            self::fail('Expected InvalidRequestException');
        } catch (InvalidRequestException $invalidRequestException) {
            self::assertSame('invalid_request', $invalidRequestException->errorCode);
            self::assertStringContainsString('request id of GET /v1/customers/{externalUserId}', $invalidRequestException->getMessage());
        }

        self::assertSame([], $fakeHttpClient->requests());
    }

    public function testTheShortestAndTheLongestIdAreSent(): void
    {
        $fakeHttpClient = (new FakeHttpClient())
            ->pushJson(200, ['data' => CustomerAnswers::customer()])
            ->pushJson(200, ['data' => CustomerAnswers::customer()]);
        $customers = Answers::sdk($fakeHttpClient)->customers();

        $customers->get('user_8421', new RequestOptions(requestId: 'a.b_c:d-'));
        $customers->get('user_8421', new RequestOptions(requestId: str_repeat('Z', 128)));

        self::assertSame('a.b_c:d-', $fakeHttpClient->requests()[0]->getHeaderLine('X-Request-Id'));
        self::assertSame(str_repeat('Z', 128), $fakeHttpClient->requests()[1]->getHeaderLine('X-Request-Id'));
    }

    /**
     * @return Iterator<string, array{Closure(SlenSdk): mixed}>
     */
    public static function everyPageProvider(): Iterator
    {
        yield 'customers.listAll' => [static function (SlenSdk $s): mixed {
            return $s->customers()->listAll(new CustomerListOptions(requestId: 'order-42:all'));
        }];
        yield 'customers.entriesAll' => [static function (SlenSdk $s): mixed {
            return $s->customers()->entriesAll('user_8421', new EntryListOptions(requestId: 'order-42:all'));
        }];
    }

    /**
     * @param Closure(SlenSdk): mixed $call
     */
    #[DataProvider('everyPageProvider')]
    public function testACallOfEveryPageRefusesAnIdOfItsOwnBeforeTheWire(Closure $call): void
    {
        $fakeHttpClient = new FakeHttpClient();

        try {
            $call(Answers::sdk($fakeHttpClient));
            self::fail('Expected InvalidRequestException');
        } catch (InvalidRequestException $invalidRequestException) {
            self::assertSame('invalid_request', $invalidRequestException->errorCode);
            self::assertStringContainsString('a request id names one request', $invalidRequestException->getMessage());
        }

        self::assertSame([], $fakeHttpClient->requests());
    }

    /**
     * @return array<string, mixed>
     */
    private static function trace(): array
    {
        return [
            'execution_id' => '74438200973594700',
            'flow_id' => '74438200973590001',
            'flow_version_id' => '74438200973591001',
            'flow_name' => 'Support replies',
            'operation' => 'support.reply',
            'operation_id' => '74438200973595001',
            'status' => 'completed',
            'error_code' => null,
            'error_message' => null,
            'cost' => ['estimated' => '0.00412500', 'actual' => '0.00390000'],
            'cache' => ['hit' => false, 'cached_at' => null],
            'started_at' => '2026-10-17T12:00:00.120+00:00',
            'finished_at' => '2026-10-17T12:00:01.010+00:00',
            'duration_ms' => 890,
            'nodes' => [],
            'events' => [],
        ];
    }
}
