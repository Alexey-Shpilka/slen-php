<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit\Modules\Customers\Resources;

use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slen\Sdk\Exceptions\InvalidResponseException;
use Slen\Sdk\Modules\Customers\Enums\CustomerStatus;
use Slen\Sdk\Modules\Customers\Resources\CustomerAccountResource;
use Slen\Sdk\Modules\Customers\Resources\CustomerResource;
use Slen\Sdk\Tests\Unit\Support\CustomerAnswers;

final class CustomerResourceTest extends TestCase
{
    public function testACustomerWithoutAccountsIsRead(): void
    {
        $customer = (new CustomerResource(new CustomerAccountResource()))->map(CustomerAnswers::customer(['accounts' => []]));

        self::assertSame(CustomerStatus::Active, $customer->status);
        self::assertSame([], $customer->accounts);
        self::assertNull($customer->blockedAt);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('unreadableProvider')]
    public function testAnAnswerTheSdkCannotReadIsRefusedRatherThanFilledIn(array $overrides, string $field): void
    {
        $this->expectException(InvalidResponseException::class);
        $this->expectExceptionMessage('"' . $field . '"');

        (new CustomerResource(new CustomerAccountResource()))->map(CustomerAnswers::customer($overrides));
    }

    /**
     * @return Iterator<string, array{array<string, mixed>, string}>
     */
    public static function unreadableProvider(): Iterator
    {
        yield 'a status of another release' => [['status' => 'suspended'], 'status'];
        yield 'a block time that is no time' => [['blocked_at' => 'yesterday-ish'], 'blocked_at'];
        yield 'accounts that are not a list of objects' => [['accounts' => ['default']], 'accounts'];
        yield 'an account with credits as a number' => [['accounts' => [CustomerAnswers::account(['balance' => 95.5])]], 'balance'];
        yield 'an id as a number' => [['customer_id' => 74438200973594624], 'customer_id'];
    }

    public function testThePageCursorIsAStringOrNothing(): void
    {
        $customerResource = new CustomerResource(new CustomerAccountResource());

        self::assertSame('7', $customerResource->page(CustomerAnswers::page([], '7'))->nextCursor);

        $this->expectException(InvalidResponseException::class);
        $this->expectExceptionMessage('"next_cursor"');

        $customerResource->page(['data' => [], 'meta' => ['limit' => 50, 'next_cursor' => 7]]);
    }
}
