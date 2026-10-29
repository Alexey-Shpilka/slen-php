<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit\Shared\Resources;

use Closure;
use DateTimeImmutable;
use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slen\Sdk\Exceptions\InvalidResponseException;
use Slen\Sdk\Shared\Http\Enums\HttpMethod;
use Slen\Sdk\Tests\Unit\Support\ExposedResource;

final class BaseResourceTest extends TestCase
{
    private const array DATA = [
        's' => 'text',
        'i' => 7,
        'b' => false,
        'd' => '-25.5000',
        't' => '2026-10-17T12:30:00+00:00',
        'e' => 'GET',
        'a' => ['x' => 1],
        'n' => null,
    ];

    public function testReadersHandBackAFieldOfTheirType(): void
    {
        $resource = new ExposedResource();

        self::assertSame('text', $resource->string(self::DATA, 's'));
        self::assertSame(7, $resource->int(self::DATA, 'i'));
        self::assertFalse($resource->bool(self::DATA, 'b'));
        self::assertSame('-25.5000', $resource->decimal(self::DATA, 'd'));
        self::assertEquals(new DateTimeImmutable('2026-10-17T12:30:00+00:00'), $resource->dateTime(self::DATA, 't'));
        self::assertSame(HttpMethod::Get, $resource->enum(self::DATA, 'e', HttpMethod::class));
        self::assertSame(['x' => 1], $resource->array(self::DATA, 'a'));
        self::assertSame([['x' => 1], []], $resource->objects(['l' => [['x' => 1], []]], 'l'));
    }

    public function testNullableReadersHandBackNullForANullOrAMissingField(): void
    {
        $resource = new ExposedResource();

        foreach (['n', 'absent'] as $key) {
            self::assertNull($resource->nullableString(self::DATA, $key));
            self::assertNull($resource->nullableInt(self::DATA, $key));
            self::assertNull($resource->nullableDecimal(self::DATA, $key));
            self::assertNull($resource->nullableDateTime(self::DATA, $key));
            self::assertNull($resource->nullableArray(self::DATA, $key));
        }
    }

    /**
     * @param Closure(ExposedResource): mixed $read
     */
    #[DataProvider('unreadableProvider')]
    public function testAReaderRefusesAFieldItCannotRead(Closure $read): void
    {
        $this->expectException(InvalidResponseException::class);
        $this->expectExceptionMessage('"f"');

        $read(new ExposedResource());
    }

    /**
     * @return Iterator<string, array{Closure(ExposedResource): mixed}>
     */
    public static function unreadableProvider(): Iterator
    {
        yield 'a missing string' => [static function (ExposedResource $r): string {
            return $r->string([], 'f');
        }];
        yield 'a number for a string' => [static function (ExposedResource $r): ?string {
            return $r->nullableString(['f' => 5], 'f');
        }];
        yield 'a missing integer' => [static function (ExposedResource $r): int {
            return $r->int(['f' => null], 'f');
        }];
        yield 'a string for an integer' => [static function (ExposedResource $r): ?int {
            return $r->nullableInt(['f' => '5'], 'f');
        }];
        yield 'a missing boolean' => [static function (ExposedResource $r): bool {
            return $r->bool([], 'f');
        }];
        yield 'a number for a boolean' => [static function (ExposedResource $r): bool {
            return $r->bool(['f' => 1], 'f');
        }];
        yield 'a float for credits' => [static function (ExposedResource $r): string {
            return $r->decimal(['f' => 25.5], 'f');
        }];
        yield 'credits that are not a number' => [static function (ExposedResource $r): ?string {
            return $r->nullableDecimal(['f' => '25,5'], 'f');
        }];
        yield 'credits in exponent form' => [static function (ExposedResource $r): string {
            return $r->decimal(['f' => '1e3'], 'f');
        }];
        yield 'a date that is not one' => [static function (ExposedResource $r): DateTimeImmutable {
            return $r->dateTime(['f' => 'noon-ish'], 'f');
        }];
        yield 'a missing date' => [static function (ExposedResource $r): DateTimeImmutable {
            return $r->dateTime([], 'f');
        }];
        yield 'a value outside the enum' => [static function (ExposedResource $r): HttpMethod {
            return $r->enum(['f' => 'PATCH'], 'f', HttpMethod::class);
        }];
        yield 'a list for an enum' => [static function (ExposedResource $r): HttpMethod {
            return $r->enum(['f' => ['GET']], 'f', HttpMethod::class);
        }];
        yield 'a string for an object' => [static function (ExposedResource $r): array {
            return $r->array(['f' => 'x'], 'f');
        }];
        yield 'a number for a nullable object' => [static function (ExposedResource $r): ?array {
            return $r->nullableArray(['f' => 3], 'f');
        }];
        yield 'a list with a string in it' => [static function (ExposedResource $r): array {
            return $r->objects(['f' => [['a' => 1], 'x']], 'f');
        }];
        yield 'a missing list' => [static function (ExposedResource $r): array {
            return $r->objects([], 'f');
        }];
    }

    public function testTheRefusalCarriesTheCodeOfAnUnreadableAnswer(): void
    {
        try {
            (new ExposedResource())->string([], 'missing');
            self::fail('Expected InvalidResponseException');
        } catch (InvalidResponseException $invalidResponseException) {
            self::assertSame('invalid_response', $invalidResponseException->errorCode);
        }
    }
}
