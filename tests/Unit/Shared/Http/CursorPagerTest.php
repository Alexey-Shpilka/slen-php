<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit\Shared\Http;

use ArrayObject;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Slen\Sdk\Exceptions\InvalidResponseException;
use Slen\Sdk\Modules\Customers\Data\CreditEntry;
use Slen\Sdk\Modules\Customers\Data\Customer;
use Slen\Sdk\Modules\Customers\Options\CustomerListOptions;
use Slen\Sdk\Modules\Customers\Options\EntryListOptions;
use Slen\Sdk\SlenConfig;
use Slen\Sdk\SlenSdk;
use Slen\Sdk\Testing\FakeHttpClient;
use Slen\Sdk\Tests\Unit\Support\Answers;
use Slen\Sdk\Tests\Unit\Support\CustomerAnswers;

/**
 * The calls of every page, `listAll()` and `entriesAll()`, read the pages through the pager as the loop asks.
 */
final class CursorPagerTest extends TestCase
{
    public function testEveryPageIsReadWithTheCursorOfThePageBeforeUntilOneHasNone(): void
    {
        $fakeHttpClient = (new FakeHttpClient())
            ->pushJson(200, CustomerAnswers::page([self::customer('a'), self::customer('b')], 'c1', 2))
            ->pushJson(200, CustomerAnswers::page([self::customer('c'), self::customer('d')], 'c2', 2))
            ->pushJson(200, CustomerAnswers::page([], null, 2));

        $customers = iterator_to_array(Answers::sdk($fakeHttpClient)->customers()->listAll(new CustomerListOptions(limit: 2)));

        self::assertSame([0, 1, 2, 3], array_keys($customers), 'the keys run on across pages');
        self::assertSame(['a', 'b', 'c', 'd'], array_map(static function (Customer $customer): string {
            return $customer->externalUserId;
        }, $customers));
        self::assertSame(
            [
                ['view' => 'customers', 'limit' => '2'],
                ['view' => 'customers', 'cursor' => 'c1', 'limit' => '2'],
                ['view' => 'customers', 'cursor' => 'c2', 'limit' => '2'],
            ],
            array_map(self::query(...), $fakeHttpClient->requests()),
        );
    }

    public function testNothingIsSentBeforeTheFirstReadAndABreakSendsNoMore(): void
    {
        $fakeHttpClient = (new FakeHttpClient())->pushJson(200, CustomerAnswers::page([self::customer('a'), self::customer('b')], 'c1', 2));
        $customers = Answers::sdk($fakeHttpClient)->customers()->listAll(new CustomerListOptions(limit: 2));
        $sentBeforeTheLoop = $fakeHttpClient->requests();

        self::assertSame([], $sentBeforeTheLoop);

        foreach ($customers as $customer) {
            self::assertSame('a', $customer->externalUserId);

            break;
        }

        self::assertCount(1, $fakeHttpClient->requests());
    }

    public function testAPageThatAnswersWithTheCursorItWasAskedWithEndsTheReadAfterItsRows(): void
    {
        $fakeHttpClient = (new FakeHttpClient())->pushJson(200, CustomerAnswers::page([CustomerAnswers::entry(), CustomerAnswers::entry()], 'c1', 2));
        /** @var ArrayObject<int, CreditEntry> $read */
        $read = new ArrayObject();

        try {
            foreach (Answers::sdk($fakeHttpClient)->customers()->entriesAll('user_8421', new EntryListOptions(cursor: 'c1', limit: 2)) as $entry) {
                $read->append($entry);
            }

            self::fail('Expected InvalidResponseException');
        } catch (InvalidResponseException $invalidResponseException) {
            self::assertSame('invalid_response', $invalidResponseException->errorCode);
        }

        self::assertCount(2, $read);
        self::assertSame(['cursor' => 'c1', 'limit' => '2'], self::query($fakeHttpClient->lastRequest()));
    }

    public function testTheTimeoutAndTheRetriesOfTheOptionsHoldForEveryPage(): void
    {
        /** @var ArrayObject<int, array{request: RequestInterface, response: ResponseInterface|null, error: mixed, options: array<mixed>}> $history */
        $history = new ArrayObject();
        $container = $history;
        $handlerStack = HandlerStack::create(new MockHandler([
            self::answer(200, CustomerAnswers::page([CustomerAnswers::entry()], 'c1', 1)),
            new Response(503, ['Retry-After' => '0']),
            self::answer(200, CustomerAnswers::page([CustomerAnswers::entry()], null, 1)),
        ]));
        $handlerStack->push(Middleware::history($container));

        $slenSdk = SlenSdk::create(new SlenConfig(Answers::KEY, timeout: 60.0, retries: 0, httpClient: new GuzzleClient(['handler' => $handlerStack])));

        $entries = iterator_to_array($slenSdk->customers()->entriesAll('user_8421', new EntryListOptions(limit: 1, timeout: 7.5, retries: 1)));

        self::assertCount(2, $entries);
        self::assertSame([7.5, 7.5, 7.5], array_map(static function (array $sent): mixed {
            return $sent['options']['timeout'] ?? null;
        }, $history->getArrayCopy()));
    }

    /**
     * @return array<string, mixed>
     */
    private static function customer(string $externalUserId): array
    {
        return CustomerAnswers::customer(['external_user_id' => $externalUserId]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function answer(int $status, array $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, string>
     */
    private static function query(RequestInterface $request): array
    {
        parse_str($request->getUri()->getQuery(), $query);

        /** @var array<string, string> $query */
        return $query;
    }
}
