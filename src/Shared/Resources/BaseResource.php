<?php

declare(strict_types=1);

namespace Slen\Sdk\Shared\Resources;

use BackedEnum;
use DateTimeImmutable;
use Slen\Sdk\Exceptions\InvalidResponseException;
use Throwable;

/** @internal */
abstract readonly class BaseResource
{
    private const string DECIMAL = '/^-?\d+(\.\d+)?$/';

    /** @param array<mixed> $data */
    protected function string(array $data, string $key): string
    {
        return $this->nullableString($data, $key) ?? throw $this->unreadable($key, 'a string');
    }

    /** @param array<mixed> $data */
    protected function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        if ($value !== null && \is_string($value) === false) {
            throw $this->unreadable($key, 'a string');
        }

        return $value;
    }

    /** @param array<mixed> $data */
    protected function int(array $data, string $key): int
    {
        return $this->nullableInt($data, $key) ?? throw $this->unreadable($key, 'an integer');
    }

    /** @param array<mixed> $data */
    protected function nullableInt(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        if ($value !== null && \is_int($value) === false) {
            throw $this->unreadable($key, 'an integer');
        }

        return $value;
    }

    /** @param array<mixed> $data */
    protected function bool(array $data, string $key): bool
    {
        $value = $data[$key] ?? null;

        return \is_bool($value) === true ? $value : throw $this->unreadable($key, 'a boolean');
    }

    /** @param array<mixed> $data */
    protected function decimal(array $data, string $key): string
    {
        return $this->nullableDecimal($data, $key) ?? throw $this->unreadable($key, 'a decimal string');
    }

    /** @param array<mixed> $data */
    protected function nullableDecimal(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        if ($value !== null && (\is_string($value) === false || preg_match(self::DECIMAL, $value) !== 1)) {
            throw $this->unreadable($key, 'a decimal string');
        }

        return $value;
    }

    /** @param array<mixed> $data */
    protected function dateTime(array $data, string $key): DateTimeImmutable
    {
        return $this->nullableDateTime($data, $key) ?? throw $this->unreadable($key, 'a date and time');
    }

    /** @param array<mixed> $data */
    protected function nullableDateTime(array $data, string $key): ?DateTimeImmutable
    {
        $value = $this->nullableString($data, $key);

        if ($value === null) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            throw $this->unreadable($key, 'a date and time');
        }
    }

    /**
     * @template E of BackedEnum
     *
     * @param array<mixed>    $data
     * @param class-string<E> $enum
     *
     * @return E
     */
    protected function enum(array $data, string $key, string $enum): BackedEnum
    {
        $value = $data[$key] ?? null;
        $case = \is_string($value) === true || \is_int($value) === true ? $enum::tryFrom($value) : null;

        return $case ?? throw $this->unreadable($key, 'one of ' . $enum);
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    protected function array(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        return \is_array($value) === true ? $value : throw $this->unreadable($key, 'an object or a list');
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>|null
     */
    protected function nullableArray(array $data, string $key): ?array
    {
        $value = $data[$key] ?? null;

        return $value === null || \is_array($value) === true ? $value : throw $this->unreadable($key, 'an object or a list');
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<array<mixed>>
     */
    protected function objects(array $data, string $key): array
    {
        $items = [];

        foreach ($this->array($data, $key) as $item) {
            $items[] = \is_array($item) === true ? $item : throw $this->unreadable($key, 'a list of objects');
        }

        return $items;
    }

    private function unreadable(string $key, string $expected): InvalidResponseException
    {
        return new InvalidResponseException(
            \sprintf('Slen answered with a field "%s" that is not %s.', $key, $expected),
            'invalid_response',
        );
    }
}
