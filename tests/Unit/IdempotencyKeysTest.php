<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Slen\Sdk\IdempotencyKeys;

final class IdempotencyKeysTest extends TestCase
{
    public function testGenerateGivesANewUuidV4EveryTime(): void
    {
        $keys = array_map(static function (int $i): string {
            return IdempotencyKeys::generate();
        }, range(1, 50));

        foreach ($keys as $key) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $key);
        }

        self::assertCount(50, array_unique($keys));
    }

    public function testForJobIsTheSameForEveryAttemptOfTheJob(): void
    {
        self::assertSame('job:9b1c:support.reply', IdempotencyKeys::forJob('9b1c', 'support.reply'));
        self::assertSame(IdempotencyKeys::forJob('9b1c', 'support.reply'), IdempotencyKeys::forJob('9b1c', 'support.reply'));
    }

    public function testForJobNeedsTheJobAndTheOperation(): void
    {
        $this->expectException(InvalidArgumentException::class);

        IdempotencyKeys::forJob(' ', 'support.reply');
    }

    public function testForJobNeedsTheOperation(): void
    {
        $this->expectException(InvalidArgumentException::class);

        IdempotencyKeys::forJob('9b1c', '');
    }
}
