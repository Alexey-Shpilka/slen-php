<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit\Support;

use BackedEnum;
use DateTimeImmutable;
use Slen\Sdk\Shared\Resources\BaseResource;

/**
 * BaseResource with its readers opened to a test.
 */
final readonly class ExposedResource extends BaseResource
{
    /**
     * @param array<mixed> $data
     */
    public function string(array $data, string $key): string
    {
        return parent::string($data, $key);
    }

    /**
     * @param array<mixed> $data
     */
    public function nullableString(array $data, string $key): ?string
    {
        return parent::nullableString($data, $key);
    }

    /**
     * @param array<mixed> $data
     */
    public function int(array $data, string $key): int
    {
        return parent::int($data, $key);
    }

    /**
     * @param array<mixed> $data
     */
    public function nullableInt(array $data, string $key): ?int
    {
        return parent::nullableInt($data, $key);
    }

    /**
     * @param array<mixed> $data
     */
    public function bool(array $data, string $key): bool
    {
        return parent::bool($data, $key);
    }

    /**
     * @param array<mixed> $data
     */
    public function decimal(array $data, string $key): string
    {
        return parent::decimal($data, $key);
    }

    /**
     * @param array<mixed> $data
     */
    public function nullableDecimal(array $data, string $key): ?string
    {
        return parent::nullableDecimal($data, $key);
    }

    /**
     * @param array<mixed> $data
     */
    public function dateTime(array $data, string $key): DateTimeImmutable
    {
        return parent::dateTime($data, $key);
    }

    /**
     * @param array<mixed> $data
     */
    public function nullableDateTime(array $data, string $key): ?DateTimeImmutable
    {
        return parent::nullableDateTime($data, $key);
    }

    /**
     * @template E of BackedEnum
     *
     * @param array<mixed>    $data
     * @param class-string<E> $enum
     *
     * @return E
     */
    public function enum(array $data, string $key, string $enum): BackedEnum
    {
        return parent::enum($data, $key, $enum);
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    public function array(array $data, string $key): array
    {
        return parent::array($data, $key);
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>|null
     */
    public function nullableArray(array $data, string $key): ?array
    {
        return parent::nullableArray($data, $key);
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<array<mixed>>
     */
    public function objects(array $data, string $key): array
    {
        return parent::objects($data, $key);
    }
}
