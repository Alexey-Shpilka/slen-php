<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit\Support;

/**
 * The answers of the API about end customers, whole.
 */
final class CustomerAnswers
{
    /**
     * `CustomerAccount`, with $overrides on top.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function account(array $overrides = []): array
    {
        return [
            'customer_account_id' => '74438200973594624',
            'customer_id' => '74438200973594624',
            'project_id' => '74438200973500001',
            'external_user_id' => 'user_8421',
            'credit_type' => 'default',
            'balance' => '95.0000',
            'reserved' => '0.0000',
            'available' => '95.0000',
            'allow_negative' => false,
            'low_balance_threshold' => '10.0000',
            'created_at' => '2026-10-17T10:00:00+00:00',
            'updated_at' => '2026-10-17T10:05:00+00:00',
            ...$overrides,
        ];
    }

    /**
     * `Customer` with one account of `default`, with $overrides on top.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function customer(array $overrides = []): array
    {
        return [
            'customer_id' => '74438200973594624',
            'external_user_id' => 'user_8421',
            'status' => 'active',
            'blocked_at' => null,
            'block_reason' => null,
            'accounts' => [self::account()],
            'created_at' => '2026-10-17T10:00:00+00:00',
            'updated_at' => '2026-10-17T10:05:00+00:00',
            ...$overrides,
        ];
    }

    /**
     * `CustomerCreditEntry`, with $overrides on top.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function entry(array $overrides = []): array
    {
        return [
            'entry_id' => '74438200973594901',
            'customer_account_id' => '74438200973594624',
            'external_user_id' => 'user_8421',
            'credit_type' => 'default',
            'entry_type' => 'charge',
            'credits' => '-5.0000',
            'balance_after' => '95.0000',
            'available_after' => '95.0000',
            'reservation_id' => null,
            'note' => 'PDF export',
            'created_at' => '2026-10-17T10:05:00+00:00',
            ...$overrides,
        ];
    }

    /**
     * `CreditPrice`, with $overrides on top.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function price(array $overrides = []): array
    {
        return [
            'credit_price_id' => '74438200973594777',
            'project_id' => '74438200973500001',
            'operation_code' => 'support.reply',
            'credit_type' => 'default',
            'credits' => '1.5000',
            'created_at' => '2026-10-17T09:00:00+00:00',
            'updated_at' => '2026-10-17T09:00:00+00:00',
            ...$overrides,
        ];
    }

    /**
     * A cursor page: the rows and `meta`.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed>
     */
    public static function page(array $rows, ?string $nextCursor = null, int $limit = 50): array
    {
        return ['data' => $rows, 'meta' => ['limit' => $limit, 'next_cursor' => $nextCursor]];
    }
}
