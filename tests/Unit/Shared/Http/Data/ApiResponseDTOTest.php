<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit\Shared\Http\Data;

use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slen\Sdk\Shared\Http\Data\ApiResponseDTO;

final class ApiResponseDTOTest extends TestCase
{
    // Fri, 16 Oct 2026 12:00:00 GMT
    private const int NOW = 1_792_152_000;

    public function testIsSuccessfulOnlyFor2xx(): void
    {
        self::assertFalse((new ApiResponseDTO(199))->isSuccessful());
        self::assertTrue((new ApiResponseDTO(200))->isSuccessful());
        self::assertTrue((new ApiResponseDTO(204))->isSuccessful());
        self::assertFalse((new ApiResponseDTO(300))->isSuccessful());
        self::assertFalse((new ApiResponseDTO(402))->isSuccessful());
    }

    public function testHeaderIgnoresTheCaseOfTheName(): void
    {
        $response = new ApiResponseDTO(200, ['x-request-id' => 'r1']);

        self::assertSame('r1', $response->header('X-Request-Id'));
        self::assertNull($response->header('Retry-After'));
    }

    public function testRequestIdComesFromTheHeaderAndElseFromTheEnvelope(): void
    {
        $body = ['error' => ['request_id' => 'from-body']];

        self::assertSame('from-header', (new ApiResponseDTO(400, ['x-request-id' => 'from-header'], $body))->requestId());
        self::assertSame('from-body', (new ApiResponseDTO(400, [], $body))->requestId());
        self::assertNull((new ApiResponseDTO(400, [], ['error' => ['request_id' => 5]]))->requestId());
        self::assertNull((new ApiResponseDTO(400, [], ['error' => 'nope']))->requestId());
    }

    public function testErrorCodeIsTheCodeOfTheEnvelopeWhenItIsAString(): void
    {
        self::assertSame('insufficient_credits', (new ApiResponseDTO(402, [], ['error' => ['code' => 'insufficient_credits']]))->errorCode());
        self::assertNull((new ApiResponseDTO(402, [], ['error' => ['code' => 402]]))->errorCode());
        self::assertNull((new ApiResponseDTO(422, [], ['message' => 'x']))->errorCode());
    }

    #[DataProvider('retryAfterProvider')]
    public function testRetryAfterSecondsReadsSecondsOrAnHttpDate(?string $header, ?int $expected): void
    {
        $headers = $header === null ? [] : ['retry-after' => $header];

        self::assertSame($expected, (new ApiResponseDTO(429, $headers))->retryAfterSeconds(self::NOW));
    }

    /**
     * @return Iterator<string, array{?string, ?int}>
     */
    public static function retryAfterProvider(): Iterator
    {
        yield 'absent' => [null, null];
        yield 'seconds' => ['5', 5];
        yield 'zero' => ['0', 0];
        yield 'padded' => [' 7 ', 7];
        yield 'negative' => ['-1', null];
        yield 'fraction' => ['1.5', null];
        yield 'a date ten seconds ahead' => ['Fri, 16 Oct 2026 12:00:10 GMT', 10];
        yield 'a date gone by' => ['Fri, 16 Oct 2026 11:59:00 GMT', 0];
        yield 'a date in another form' => ['2026-10-16 12:00:10', null];
        yield 'empty' => ['', null];
    }

    public function testAttemptedKeepsTheAnswer(): void
    {
        $response = new ApiResponseDTO(200, ['x' => 'y'], ['status' => 'ok'], false);

        $attempted = $response->attempted(3, true);

        self::assertSame(3, $attempted->attempt);
        self::assertTrue($attempted->afterNoAnswer);
        self::assertSame(1, $response->attempt);
        self::assertFalse($response->afterNoAnswer);
        self::assertSame([200, ['x' => 'y'], ['status' => 'ok'], false], [$attempted->status, $attempted->headers, $attempted->body, $attempted->readable]);
    }
}
