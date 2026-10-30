<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit\Modules\Customers;

use Closure;
use DateTimeImmutable;
use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Slen\Sdk\Exceptions\CustomerNotFoundException;
use Slen\Sdk\Exceptions\IdempotencyConflictException;
use Slen\Sdk\Exceptions\InsufficientCreditsException;
use Slen\Sdk\Exceptions\InvalidRequestException;
use Slen\Sdk\Exceptions\InvalidResponseException;
use Slen\Sdk\Modules\Customers\CustomersFacade;
use Slen\Sdk\Modules\Customers\Data\CreditPrice;
use Slen\Sdk\Modules\Customers\Data\CustomerAccount;
use Slen\Sdk\Modules\Customers\Enums\CreditEntryType;
use Slen\Sdk\Modules\Customers\Enums\CustomerStatus;
use Slen\Sdk\Modules\Customers\Options\AccountOptions;
use Slen\Sdk\Modules\Customers\Options\BlockOptions;
use Slen\Sdk\Modules\Customers\Options\CustomerListOptions;
use Slen\Sdk\Modules\Customers\Options\EntryListOptions;
use Slen\Sdk\Modules\Customers\Options\MovementOptions;
use Slen\Sdk\Modules\Customers\Options\PriceListOptions;
use Slen\Sdk\Testing\FakeHttpClient;
use Slen\Sdk\Testing\RecordingSpanRecorder;
use Slen\Sdk\Tests\Unit\Support\Answers;
use Slen\Sdk\Tests\Unit\Support\CustomerAnswers;
use Slen\Sdk\Tests\Unit\Support\NetworkFailure;
use Slen\Sdk\Tests\Unit\Support\RecordingLogger;

final class CustomersFacadeTest extends TestCase
{
    private const string BASE = 'https://api.slen.ai/api';

    private const string UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    public function testListAsksForThePageOfCustomersWithEveryFilterGiven(): void
    {
        $fakeHttpClient = (new FakeHttpClient())->pushJson(200, CustomerAnswers::page([CustomerAnswers::customer()], '74438200973594624', 2));

        $customerPage = Answers::sdk($fakeHttpClient)->customers()->list(new CustomerListOptions(
            cursor: '74438200973594999',
            limit: 2,
            search: 'user_',
            status: CustomerStatus::Active,
            creditType: 'default',
            lowBalance: '10',
        ));

        self::assertSame('GET', $fakeHttpClient->lastRequest()->getMethod());
        self::assertSame('/api/v1/customers', $fakeHttpClient->lastRequest()->getUri()->getPath());
        self::assertSame(
            [
                'view' => 'customers',
                'cursor' => '74438200973594999',
                'limit' => '2',
                'q' => 'user_',
                'status' => 'active',
                'credit_type' => 'default',
                'low_balance' => '10',
            ],
            self::query($fakeHttpClient->lastRequest()),
        );
        self::assertSame('', (string) $fakeHttpClient->lastRequest()->getBody());
        self::assertCount(1, $customerPage->data);
        self::assertSame('user_8421', $customerPage->data[0]->externalUserId);
        self::assertSame('74438200973594624', $customerPage->nextCursor);
    }

    public function testListWithNothingGivenSendsTheViewAndTheLimitAlone(): void
    {
        $fakeHttpClient = (new FakeHttpClient())->pushJson(200, CustomerAnswers::page([]));

        $customerPage = Answers::sdk($fakeHttpClient)->customers()->list();

        self::assertSame(['view' => 'customers', 'limit' => '50'], self::query($fakeHttpClient->lastRequest()));
        self::assertSame([], $customerPage->data);
        self::assertNull($customerPage->nextCursor);
    }

    public function testAPageWithoutMetaCannotBeRead(): void
    {
        $fakeHttpClient = (new FakeHttpClient())->pushJson(200, ['data' => [CustomerAnswers::customer()]]);

        $this->expectException(InvalidResponseException::class);

        Answers::sdk($fakeHttpClient)->customers()->list();
    }

    public function testGetReadsTheCustomerWithItsAccountsByCreditType(): void
    {
        $answer = CustomerAnswers::customer([
            'status' => 'blocked',
            'blocked_at' => '2026-10-17T11:00:00+00:00',
            'block_reason' => 'chargeback',
            'accounts' => [CustomerAnswers::account(), CustomerAnswers::account(['credit_type' => 'search', 'balance' => '-2.5000', 'available' => '-2.5000'])],
        ]);
        $fakeHttpClient = (new FakeHttpClient())->pushJson(200, ['data' => $answer]);

        $customer = Answers::sdk($fakeHttpClient)->customers()->get('user_8421');

        self::assertSame('GET', $fakeHttpClient->lastRequest()->getMethod());
        self::assertSame(self::BASE . '/v1/customers/user_8421', (string) $fakeHttpClient->lastRequest()->getUri());
        self::assertSame('74438200973594624', $customer->customerId);
        self::assertSame(CustomerStatus::Blocked, $customer->status);
        self::assertTrue($customer->isBlocked());
        self::assertEquals(new DateTimeImmutable('2026-10-17T11:00:00+00:00'), $customer->blockedAt);
        self::assertSame('chargeback', $customer->blockReason);
        self::assertSame(['default', 'search'], array_map(static function (CustomerAccount $account): string {
            return $account->creditType;
        }, $customer->accounts));
        self::assertSame('95.0000', $customer->account()?->available);
        self::assertSame('-2.5000', $customer->account('search')?->balance);
        self::assertNull($customer->account('enrichment'));
        self::assertEquals(new DateTimeImmutable('2026-10-17T10:05:00+00:00'), $customer->updatedAt);
    }

    public function testTheIdIsOnePathSegmentWhateverItHolds(): void
    {
        $fakeHttpClient = (new FakeHttpClient())->pushJson(200, ['data' => CustomerAnswers::customer(['external_user_id' => 'a/../b c'])]);

        Answers::sdk($fakeHttpClient)->customers()->get('a/../b c');

        self::assertSame('/api/v1/customers/a%2F..%2Fb%20c', $fakeHttpClient->lastRequest()->getUri()->getPath());
    }

    public function testACustomerTheProjectDoesNotHaveIsCustomerNotFound(): void
    {
        $fakeHttpClient = (new FakeHttpClient())->pushJson(404, Answers::refusal('customer_not_found'));

        $this->expectException(CustomerNotFoundException::class);

        Answers::sdk($fakeHttpClient)->customers()->get('user_404');
    }

    public function testCreatePutsAnEmptyObjectAndReadsTheCustomer(): void
    {
        $fakeHttpClient = (new FakeHttpClient())->pushJson(201, ['data' => CustomerAnswers::customer(['accounts' => []])]);

        $customer = Answers::sdk($fakeHttpClient)->customers()->create('user_8421');

        self::assertSame('PUT', $fakeHttpClient->lastRequest()->getMethod());
        self::assertSame(self::BASE . '/v1/customers/user_8421', (string) $fakeHttpClient->lastRequest()->getUri());
        self::assertSame('{}', (string) $fakeHttpClient->lastRequest()->getBody());
        self::assertSame('', $fakeHttpClient->lastRequest()->getHeaderLine('Idempotency-Key'));
        self::assertSame([], $customer->accounts);
        self::assertNull($customer->account());
    }

    public function testUpdateAccountPutsBothSettingsOfTheAccountOfTheCreditType(): void
    {
        $fakeHttpClient = (new FakeHttpClient())->pushJson(200, ['data' => CustomerAnswers::account(['credit_type' => 'search', 'allow_negative' => true])]);

        $customerAccount = Answers::sdk($fakeHttpClient)->customers()
            ->updateAccount('user_8421', 'search', new AccountOptions(allowNegative: true, lowBalanceThreshold: '5.0000'));

        self::assertSame('PUT', $fakeHttpClient->lastRequest()->getMethod());
        self::assertSame(self::BASE . '/v1/customers/user_8421/accounts/search', (string) $fakeHttpClient->lastRequest()->getUri());
        self::assertSame(['allow_negative' => true, 'low_balance_threshold' => '5.0000'], self::body($fakeHttpClient->lastRequest()));
        self::assertTrue($customerAccount->allowNegative);
        self::assertSame('search', $customerAccount->creditType);
    }

    public function testUpdateAccountSendsTheDefaultsWhenNothingIsGiven(): void
    {
        $fakeHttpClient = (new FakeHttpClient())->pushJson(201, ['data' => CustomerAnswers::account()]);

        Answers::sdk($fakeHttpClient)->customers()->updateAccount('user_8421', 'default');

        self::assertSame(['allow_negative' => false, 'low_balance_threshold' => '0'], self::body($fakeHttpClient->lastRequest()));
    }

    public function testBlockSendsTheReasonOnlyWhenOneIsGivenAndUnblockSendsAnEmptyObject(): void
    {
        $blocked = ['data' => CustomerAnswers::customer(['status' => 'blocked', 'blocked_at' => '2026-10-17T11:00:00+00:00'])];
        $fakeHttpClient = (new FakeHttpClient())
            ->pushJson(200, $blocked)
            ->pushJson(200, $blocked)
            ->pushJson(200, ['data' => CustomerAnswers::customer()]);
        $customersFacade = Answers::sdk($fakeHttpClient)->customers();

        self::assertTrue($customersFacade->block('user_8421', new BlockOptions('chargeback'))->isBlocked());
        $customersFacade->block('user_8421');
        self::assertFalse($customersFacade->unblock('user_8421')->isBlocked());

        [$withReason, $without, $unblock] = $fakeHttpClient->requests();

        self::assertSame(['POST', '/api/v1/customers/user_8421/block', '{"reason":"chargeback"}'], self::line($withReason));
        self::assertSame(['POST', '/api/v1/customers/user_8421/block', '{}'], self::line($without));
        self::assertSame(['POST', '/api/v1/customers/user_8421/unblock', '{}'], self::line($unblock));
    }

    public function testDeleteSendsNothingAndAnswersNothing(): void
    {
        $fakeHttpClient = (new FakeHttpClient())->pushJson(204, []);

        Answers::sdk($fakeHttpClient)->customers()->delete('user_8421');

        self::assertSame(['DELETE', '/api/v1/customers/user_8421', ''], self::line($fakeHttpClient->lastRequest()));
    }

    public function testDeletingACustomerWithHistoryIsARequestToFix(): void
    {
        $fakeHttpClient = (new FakeHttpClient())->pushJson(409, Answers::refusal('customer_has_history', 'Block the customer instead.'));

        try {
            Answers::sdk($fakeHttpClient, retries: 2)->customers()->delete('user_8421');
            self::fail('the refusal was not thrown');
        } catch (InvalidRequestException $invalidRequestException) {
            self::assertSame('customer_has_history', $invalidRequestException->errorCode);
            self::assertSame(409, $invalidRequestException->statusCode);
        }

        self::assertCount(1, $fakeHttpClient->requests());
    }

    public function testADeleteRepeatedAfterNoAnswerTakesTheMissingCustomerForItsOwnWork(): void
    {
        $fakeHttpClient = (new FakeHttpClient())
            ->pushException(new NetworkFailure())
            ->pushJson(404, Answers::refusal('customer_not_found'));

        Answers::sdk($fakeHttpClient, retries: 1)->customers()->delete('user_8421');

        self::assertCount(2, $fakeHttpClient->requests());
    }

    public function testADeleteOfACustomerThatWasNeverThereIsCustomerNotFound(): void
    {
        $fakeHttpClient = (new FakeHttpClient())->pushJson(404, Answers::refusal('customer_not_found'));

        $this->expectException(CustomerNotFoundException::class);

        Answers::sdk($fakeHttpClient, retries: 1)->customers()->delete('user_8421');
    }

    /**
     * @param Closure(CustomersFacade): CustomerAccount $move
     * @param array<string, string>                         $body
     */
    #[DataProvider('movementProvider')]
    public function testAMovementPostsTheCreditsUnderTheCallersKey(Closure $move, string $path, array $body): void
    {
        $fakeHttpClient = (new FakeHttpClient())->pushJson(200, ['data' => CustomerAnswers::account()]);

        $customerAccount = $move(Answers::sdk($fakeHttpClient)->customers());
        $request = $fakeHttpClient->lastRequest();

        self::assertSame('POST', $request->getMethod());
        self::assertSame('/api' . $path, $request->getUri()->getPath());
        self::assertSame($body, self::body($request));
        self::assertSame('invoice-7781:move', $request->getHeaderLine('Idempotency-Key'));
        self::assertSame('95.0000', $customerAccount->balance);
    }

    /**
     * @return Iterator<string, array{Closure(CustomersFacade): CustomerAccount, string, array<string, string>}>
     */
    public static function movementProvider(): Iterator
    {
        yield 'a top-up' => [
            static function (CustomersFacade $c): CustomerAccount {
                return $c->topUp(
                    'user_8421',
                    '100.0000',
                    new MovementOptions('search', 'invoice 7781', 'invoice-7781:move'),
                );
            },
            '/v1/customers/user_8421/topup',
            ['credits' => '100.0000', 'credit_type' => 'search', 'note' => 'invoice 7781'],
        ];
        yield 'a charge' => [
            static function (CustomersFacade $c): CustomerAccount {
                return $c->charge(
                    'user_8421',
                    '5',
                    new MovementOptions(note: 'PDF export', idempotencyKey: 'invoice-7781:move'),
                );
            },
            '/v1/customers/user_8421/charge',
            ['credits' => '5', 'credit_type' => 'default', 'note' => 'PDF export'],
        ];
        yield 'a correction that takes credits' => [
            static function (CustomersFacade $c): CustomerAccount {
                return $c->adjust('user_8421', '-2.5', new MovementOptions(idempotencyKey: 'invoice-7781:move'));
            },
            '/v1/customers/user_8421/adjust',
            ['credits' => '-2.5', 'credit_type' => 'default'],
        ];
        yield 'a correction that gives credits' => [
            static function (CustomersFacade $c): CustomerAccount {
                return $c->adjust(
                    'user_8421',
                    '0.0001',
                    new MovementOptions('search', idempotencyKey: 'invoice-7781:move'),
                );
            },
            '/v1/customers/user_8421/adjust',
            ['credits' => '0.0001', 'credit_type' => 'search'],
        ];
    }

    public function testAMovementWithoutAKeyGetsOneOfItsOwnAndKeepsItOnARepeat(): void
    {
        $fakeHttpClient = (new FakeHttpClient())
            ->pushJson(503, Answers::refusal('ledger_unavailable'), ['Retry-After' => '0'])
            ->pushJson(200, ['data' => CustomerAnswers::account()]);

        Answers::sdk($fakeHttpClient, retries: 1)->customers()->topUp('user_8421', '10');

        [$first, $second] = array_map(static function (RequestInterface $request): string {
            return $request->getHeaderLine('Idempotency-Key');
        }, $fakeHttpClient->requests());

        self::assertMatchesRegularExpression(self::UUID_V4, $first);
        self::assertSame($first, $second);
    }

    /**
     * @param Closure(CustomersFacade): mixed $move
     */
    #[DataProvider('refusedBeforeTheWireProvider')]
    public function testAnAmountTheMovementCannotTakeIsRefusedBeforeTheWire(Closure $move, string $message): void
    {
        $fakeHttpClient = new FakeHttpClient();
        $recordingLogger = new RecordingLogger();

        try {
            $move(Answers::sdk($fakeHttpClient, logger: $recordingLogger)->customers());
            self::fail('the call was not refused');
        } catch (InvalidRequestException $invalidRequestException) {
            self::assertSame('invalid_request', $invalidRequestException->errorCode);
            self::assertStringContainsString($message, $invalidRequestException->getMessage());
        }

        self::assertSame([], $fakeHttpClient->requests());
        self::assertSame([], $recordingLogger->records);
    }

    /**
     * @return Iterator<string, array{Closure(CustomersFacade): mixed, string}>
     */
    public static function refusedBeforeTheWireProvider(): Iterator
    {
        $notAnAmount = 'the credits of POST /v1/customers/{externalUserId}/%s are not a decimal string of at most four places';
        $notAboveZero = 'the credits of POST /v1/customers/{externalUserId}/%s are not above zero';

        yield 'a top-up of five places' => [static function (CustomersFacade $c): mixed {
            return $c->topUp('u', '1.00001');
        }, \sprintf($notAnAmount, 'topup')];
        yield 'a top-up written with a comma' => [static function (CustomersFacade $c): mixed {
            return $c->topUp('u', '1,5');
        }, \sprintf($notAnAmount, 'topup')];
        yield 'a top-up with a plus' => [static function (CustomersFacade $c): mixed {
            return $c->topUp('u', '+1');
        }, \sprintf($notAnAmount, 'topup')];
        yield 'a top-up of nothing' => [static function (CustomersFacade $c): mixed {
            return $c->topUp('u', '');
        }, \sprintf($notAnAmount, 'topup')];
        yield 'a top-up in exponent form' => [static function (CustomersFacade $c): mixed {
            return $c->topUp('u', '1e3');
        }, \sprintf($notAnAmount, 'topup')];
        yield 'a top-up of zero' => [static function (CustomersFacade $c): mixed {
            return $c->topUp('u', '0.0000');
        }, \sprintf($notAboveZero, 'topup')];
        yield 'a negative top-up' => [static function (CustomersFacade $c): mixed {
            return $c->topUp('u', '-5');
        }, \sprintf($notAboveZero, 'topup')];
        yield 'a charge of minus zero' => [static function (CustomersFacade $c): mixed {
            return $c->charge('u', '-0');
        }, \sprintf($notAboveZero, 'charge')];
        yield 'a negative charge' => [static function (CustomersFacade $c): mixed {
            return $c->charge('u', '-1.5');
        }, \sprintf($notAboveZero, 'charge')];
        yield 'a correction of zero' => [
            static function (CustomersFacade $c): mixed {
                return $c->adjust('u', '-0.00');
            },
            'the credits of POST /v1/customers/{externalUserId}/adjust are zero',
        ];
        yield 'a correction that is no number' => [static function (CustomersFacade $c): mixed {
            return $c->adjust('u', 'five');
        }, \sprintf($notAnAmount, 'adjust')];
        yield 'an empty credit type' => [
            static function (CustomersFacade $c): mixed {
                return $c->charge('u', '1', new MovementOptions(' '));
            },
            'the creditType of POST /v1/customers/{externalUserId}/charge is empty',
        ];
        yield 'an empty key' => [
            static function (CustomersFacade $c): mixed {
                return $c->topUp('u', '1', new MovementOptions(idempotencyKey: ''));
            },
            'the idempotency key of POST /v1/customers/{externalUserId}/topup is empty',
        ];
        yield 'an empty customer of a movement' => [
            static function (CustomersFacade $c): mixed {
                return $c->adjust(' ', '1');
            },
            'the externalUserId of POST /v1/customers/{externalUserId}/adjust is empty',
        ];
        yield 'an empty customer of a read' => [
            static function (CustomersFacade $c): mixed {
                return $c->get('');
            },
            'the externalUserId of GET /v1/customers/{externalUserId} is empty',
        ];
        yield 'an empty credit type of the settings' => [
            static function (CustomersFacade $c): mixed {
                return $c->updateAccount('u', '');
            },
            'the creditType of PUT /v1/customers/{externalUserId}/accounts/{creditType} is empty',
        ];
        yield 'an empty customer of a delete' => [
            static function (CustomersFacade $c): mixed {
                $c->delete('');

                return null;
            },
            'the externalUserId of DELETE /v1/customers/{externalUserId} is empty',
        ];
    }

    public function testAChargeTheAccountCannotCoverIsInsufficientCredits(): void
    {
        $fakeHttpClient = (new FakeHttpClient())->pushJson(402, Answers::refusal('insufficient_credits'));

        $this->expectException(InsufficientCreditsException::class);

        Answers::sdk($fakeHttpClient)->customers()->charge('user_8421', '500', new MovementOptions(idempotencyKey: 'export-991:charge'));
    }

    public function testAKeyThatNamesAnotherMovementIsAConflict(): void
    {
        $fakeHttpClient = (new FakeHttpClient())->pushJson(409, Answers::refusal('idempotency_conflict'));

        $this->expectException(IdempotencyConflictException::class);

        Answers::sdk($fakeHttpClient, retries: 2)->customers()->topUp('user_8421', '10', new MovementOptions(idempotencyKey: 'invoice-7781:topup'));
    }

    public function testEntriesAsksForOnePageOfTheStatementWithEveryFilterGiven(): void
    {
        $hold = CustomerAnswers::entry(['entry_type' => 'hold', 'note' => null, 'reservation_id' => '74438200973594800']);
        $answer = CustomerAnswers::page([CustomerAnswers::entry(), $hold], null, 2);
        $fakeHttpClient = (new FakeHttpClient())->pushJson(200, $answer);

        $creditEntryPage = Answers::sdk($fakeHttpClient)->customers()
            ->entries('user_8421', new EntryListOptions('74438200973594999', 2, 'default', CreditEntryType::Charge));

        self::assertSame('/api/v1/customers/user_8421/entries', $fakeHttpClient->lastRequest()->getUri()->getPath());
        self::assertSame(
            ['cursor' => '74438200973594999', 'limit' => '2', 'credit_type' => 'default', 'entry_type' => 'charge'],
            self::query($fakeHttpClient->lastRequest()),
        );
        self::assertSame(
            [CreditEntryType::Charge, CreditEntryType::Hold],
            array_map(static function ($entry): CreditEntryType {
                return $entry->entryType;
            }, $creditEntryPage->data),
        );
        self::assertSame('PDF export', $creditEntryPage->data[0]->note);
        self::assertSame('-5.0000', $creditEntryPage->data[0]->credits);
        self::assertSame('74438200973594800', $creditEntryPage->data[1]->reservationId);
        self::assertNull($creditEntryPage->nextCursor);
    }

    public function testEntriesWithNothingGivenSendsTheLimitAlone(): void
    {
        $fakeHttpClient = (new FakeHttpClient())->pushJson(200, CustomerAnswers::page([]));

        Answers::sdk($fakeHttpClient)->customers()->entries('user_8421');

        self::assertSame(['limit' => '50'], self::query($fakeHttpClient->lastRequest()));
    }

    public function testAnEntryOfATypeTheSdkDoesNotKnowCannotBeRead(): void
    {
        $fakeHttpClient = (new FakeHttpClient())->pushJson(200, CustomerAnswers::page([CustomerAnswers::entry(['entry_type' => 'refund'])]));

        $this->expectException(InvalidResponseException::class);
        $this->expectExceptionMessage('"entry_type"');

        Answers::sdk($fakeHttpClient)->customers()->entries('user_8421');
    }

    public function testPricesAreReadByTheFiltersGiven(): void
    {
        $fakeHttpClient = (new FakeHttpClient())
            ->pushJson(200, ['data' => [CustomerAnswers::price(), CustomerAnswers::price(['credit_type' => 'search', 'credits' => '0.0000'])]])
            ->pushJson(200, ['data' => []]);
        $customersFacade = Answers::sdk($fakeHttpClient)->customers();

        $prices = $customersFacade->prices(new PriceListOptions('support.reply', 'default'));
        $none = $customersFacade->prices();

        self::assertSame('/api/v1/credit-prices', $fakeHttpClient->requests()[0]->getUri()->getPath());
        self::assertSame(['operation_code' => 'support.reply', 'credit_type' => 'default'], self::query($fakeHttpClient->requests()[0]));
        self::assertSame([], self::query($fakeHttpClient->requests()[1]));
        self::assertSame(['1.5000', '0.0000'], array_map(static function (CreditPrice $price): string {
            return $price->credits;
        }, $prices->data));
        self::assertSame('support.reply', $prices->data[0]->operationCode);
        self::assertSame([], $none->data);
    }

    public function testAnAccountWithoutOneOfItsFieldsCannotBeRead(): void
    {
        $account = CustomerAnswers::account();
        unset($account['available']);
        $fakeHttpClient = (new FakeHttpClient())->pushJson(200, ['data' => $account]);

        $this->expectException(InvalidResponseException::class);
        $this->expectExceptionMessage('"available"');

        Answers::sdk($fakeHttpClient)->customers()->topUp('user_8421', '1', new MovementOptions(idempotencyKey: 'invoice-1:topup'));
    }

    public function testACallOfTheCustomersWritesNoSpan(): void
    {
        $fakeHttpClient = (new FakeHttpClient())
            ->pushJson(200, ['data' => CustomerAnswers::customer()])
            ->pushJson(200, ['data' => CustomerAnswers::account()]);
        $recordingSpanRecorder = new RecordingSpanRecorder();
        $customersFacade = Answers::sdk($fakeHttpClient, $recordingSpanRecorder)->customers();

        $customersFacade->get('user_8421');
        $customersFacade->charge('user_8421', '1', new MovementOptions(idempotencyKey: 'export-1:charge'));

        self::assertSame([], $recordingSpanRecorder->spans());
    }

    public function testTheJournalNamesTheRouteAndNeverTheCustomerTheNoteOrTheKey(): void
    {
        $fakeHttpClient = (new FakeHttpClient())->pushJson(402, Answers::refusal('insufficient_credits', 'canary-message'));
        $recordingLogger = new RecordingLogger();

        try {
            Answers::sdk($fakeHttpClient, logger: $recordingLogger)->customers()
                ->charge('canary@example.com', '5', new MovementOptions(note: 'canary-note', idempotencyKey: 'canary-key'));
            self::fail('the refusal was not thrown');
        } catch (InsufficientCreditsException) {
        }

        $journal = json_encode($recordingLogger->records, JSON_THROW_ON_ERROR);

        self::assertSame('/v1/customers/{externalUserId}/charge', $recordingLogger->context('slen.refused')['route'] ?? null);

        foreach (['canary@example.com', 'canary%40example.com', 'canary-note', 'canary-key', 'canary-message'] as $canary) {
            self::assertStringNotContainsString($canary, $journal);
        }
    }

    public function testTheSdkHandsOutOneFacadeOfTheCustomers(): void
    {
        $slenSdk = Answers::sdk(new FakeHttpClient());

        self::assertSame($slenSdk->customers(), $slenSdk->customers());
    }

    /**
     * @return array<string, mixed>
     */
    private static function body(RequestInterface $request): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
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

    /**
     * @return array{string, string, string}
     */
    private static function line(RequestInterface $request): array
    {
        return [$request->getMethod(), $request->getUri()->getPath(), (string) $request->getBody()];
    }
}
