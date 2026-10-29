<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit\Shared\Http\Data;

use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slen\Sdk\Exceptions\InvalidRequestException;
use Slen\Sdk\Shared\Http\Data\ApiRequestDTO;
use Slen\Sdk\Shared\Http\Enums\Endpoint;

final class ApiRequestDTOTest extends TestCase
{
    public function testConstructRefusesAnEmptyValueOfThePath(): void
    {
        try {
            new ApiRequestDTO(Endpoint::DeleteCustomer, ['externalUserId' => '  ']);
            self::fail('Expected InvalidRequestException');
        } catch (InvalidRequestException $invalidRequestException) {
            self::assertSame('invalid_request', $invalidRequestException->errorCode);
            self::assertNull($invalidRequestException->statusCode);
            self::assertStringContainsString('externalUserId', $invalidRequestException->getMessage());
        }
    }

    public function testConstructRefusesAMissingValueOfThePath(): void
    {
        $this->expectException(InvalidRequestException::class);

        new ApiRequestDTO(Endpoint::GetCustomer);
    }

    public function testConstructEncodesTheBodyAsTheWireExpectsIt(): void
    {
        $request = new ApiRequestDTO(Endpoint::ExecuteOperation, body: ['note' => 'Ünïcode/ok', 'amount' => 1.0]);

        self::assertSame('{"note":"Ünïcode/ok","amount":1.0}', $request->json);
        self::assertSame('{}', (new ApiRequestDTO(Endpoint::ExecuteOperation, body: []))->json);
        self::assertNull((new ApiRequestDTO(Endpoint::ListPrices))->json);
    }

    public function testConstructRefusesABodyThatIsNotJson(): void
    {
        try {
            new ApiRequestDTO(Endpoint::ExecuteOperation, body: ['note' => "\xB1\x31"]);
            self::fail('Expected InvalidRequestException');
        } catch (InvalidRequestException $invalidRequestException) {
            self::assertSame('invalid_request', $invalidRequestException->errorCode);
            self::assertNotNull($invalidRequestException->getPrevious());
        }
    }

    public function testConstructRefusesAnEmptyIdempotencyKey(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('idempotency key');

        new ApiRequestDTO(Endpoint::ExecuteOperation, body: [], idempotencyKey: ' ');
    }

    public function testWithHeaderWithAttemptAndWithTimeoutKeepEverythingElse(): void
    {
        $request = new ApiRequestDTO(
            Endpoint::Charge,
            ['externalUserId' => 'u1'],
            ['a' => 1],
            ['credits' => '1.0000'],
            'key',
            false,
            0,
            2.5,
            ['X-One' => '1'],
        );

        $changed = $request->withHeader('X-Two', '2')->withAttempt(3);

        self::assertSame(2.5, $changed->timeout);
        self::assertSame(7.0, $changed->withTimeout(7.0)->timeout);
        self::assertSame(3, $changed->withTimeout(7.0)->attempt);

        self::assertSame(Endpoint::Charge, $changed->endpoint);
        self::assertSame($request->pathParams, $changed->pathParams);
        self::assertSame($request->query, $changed->query);
        self::assertSame($request->body, $changed->body);
        self::assertSame('key', $changed->idempotencyKey);
        self::assertFalse($changed->envelope);
        self::assertSame(0, $changed->retries);
        self::assertSame($request->json, $changed->json);
        self::assertSame(['X-One' => '1', 'X-Two' => '2'], $changed->headers);
        self::assertSame(3, $changed->attempt);
        self::assertSame(1, $request->attempt);
    }

    #[DataProvider('outOfRangeProvider')]
    public function testATimeoutOrRetriesOutOfRangeAreRefusedBeforeTheWire(?float $timeout, ?int $retries, string $message): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage($message);

        new ApiRequestDTO(Endpoint::GetCustomer, ['externalUserId' => 'jane@example.com'], retries: $retries, timeout: $timeout);
    }

    /**
     * @return Iterator<string, array{?float, ?int, string}>
     */
    public static function outOfRangeProvider(): Iterator
    {
        $timeout = 'Slen: the timeout of GET /v1/customers/{externalUserId} must be a positive number of seconds, got ';

        yield 'a timeout of zero' => [0.0, null, $timeout . '0.0.'];
        yield 'a negative timeout' => [-1.0, null, $timeout . '-1.0.'];
        yield 'an infinite timeout' => [INF, null, $timeout . 'INF.'];
        yield 'a timeout that is not a number' => [NAN, null, $timeout . 'NAN.'];
        yield 'negative retries' => [null, -1, 'Slen: the retries of GET /v1/customers/{externalUserId} must be zero or more, got -1.'];
    }

    public function testDescribeNamesTheRouteAndNoValue(): void
    {
        $request = new ApiRequestDTO(Endpoint::Charge, ['externalUserId' => 'jane@example.com']);

        self::assertSame('POST /v1/customers/{externalUserId}/charge', $request->describe());
    }

    public function testADumpShowsNeitherTheHeadersNorTheValues(): void
    {
        $request = (new ApiRequestDTO(Endpoint::GetCustomer, ['externalUserId' => 'jane@example.com']))
            ->withHeader('Authorization', 'Bearer sk_canary');

        $dump = print_r($request, true);

        self::assertStringNotContainsString('sk_canary', $dump);
        self::assertStringNotContainsString('jane', $dump);
        self::assertStringContainsString('/v1/customers/{externalUserId}', $dump);
    }
}
