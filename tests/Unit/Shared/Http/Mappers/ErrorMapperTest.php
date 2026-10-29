<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit\Shared\Http\Mappers;

use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;
use Slen\Sdk\Exceptions\InvalidRequestException;
use Slen\Sdk\Exceptions\ProviderException;
use Slen\Sdk\Exceptions\RateLimitException;
use Slen\Sdk\Exceptions\SlenException;
use Slen\Sdk\Exceptions\SpendRefusedException;
use Slen\Sdk\Shared\Http\Data\ApiResponseDTO;
use Slen\Sdk\Shared\Http\Mappers\ErrorMapper;
use Slen\Sdk\Tests\Paths;

/**
 * The table is read from contract/error-codes.json, the file the TypeScript suite builds its cases from too.
 */
final class ErrorMapperTest extends TestCase
{
    private const string CONTRACT = Paths::CONTRACT . '/error-codes.json';

    private const string EXCEPTIONS = __DIR__ . '/../../../../../src/Exceptions';

    private const string NAMESPACE = 'Slen\\Sdk\\Exceptions\\';

    // A status no entry of the contract uses and no fallback rule names.
    private const int NEUTRAL_STATUS = 418;

    #[DataProvider('codeProvider')]
    public function testMapTurnsAContractCodeIntoItsType(string $code, int $status, string $type): void
    {
        $exception = $this->refusal($status, $code);

        self::assertSame($exception::class, self::classOf($type));
        self::assertSame($code, $exception->errorCode);
        self::assertSame($status, $exception->statusCode);
        self::assertSame('boom', $exception->getMessage());
        self::assertSame($type, $exception::type());
    }

    #[DataProvider('codeProvider')]
    public function testMapDoesNotLetTheStatusChangeTheTypeOfAContractCode(string $code, int $status, string $type): void
    {
        self::assertSame($this->refusal(self::NEUTRAL_STATUS, $code)::class, self::classOf($type));
    }

    #[DataProvider('fallbackProvider')]
    public function testMapFallsBackByStatusForACodeOutsideTheTable(int $status, string $type): void
    {
        $exception = $this->refusal($status, 'some_future_code');

        self::assertSame($exception::class, self::classOf($type));
        self::assertSame('some_future_code', $exception->errorCode);
    }

    public function testTheTableHoldsExactlyTheCodesOfTheContract(): void
    {
        /** @var array<string, class-string<SlenException>> $table */
        $table = (new ReflectionClassConstant(ErrorMapper::class, 'BY_CODE'))->getValue();
        $expected = [];

        foreach (self::contract()['codes'] as $code => $entry) {
            $expected[$code] = self::classOf($entry['type']);
        }

        ksort($table);
        ksort($expected);

        self::assertSame($expected, $table);
    }

    #[DataProvider('typeProvider')]
    public function testATypeSitsWhereTheContractPutsIt(string $type, string $parent): void
    {
        self::assertTrue(class_exists(self::classOf($type)), $type . ' has no exception class');
        self::assertSame(self::classOf($parent), get_parent_class(self::classOf($type)));
    }

    public function testEveryExceptionClassIsATypeOfTheContract(): void
    {
        $files = glob(self::EXCEPTIONS . '/*.php');
        self::assertIsArray($files);
        $classes = array_map(static function (string $file): string {
            return basename($file, 'Exception.php');
        }, $files);

        $types = ['Slen', ...array_keys(self::contract()['types'])];
        sort($classes);
        sort($types);

        self::assertSame($types, $classes);
    }

    #[DataProvider('localCodeProvider')]
    public function testALocalCodeHasATypeOfTheHierarchy(string $code, string $type): void
    {
        self::assertTrue(class_exists(self::classOf($type)), $type . ' has no exception class');
        self::assertArrayNotHasKey($code, self::contract()['codes'], $code . ' is listed both as a wire code and as a local one');
    }

    public function testMapPutsEveryUnnamed402InTheFamilyOfRefusedSpend(): void
    {
        self::assertSame(SpendRefusedException::class, $this->refusal(402, 'a_402_of_the_future')::class);
        self::assertInstanceOf(SpendRefusedException::class, $this->refusal(402, 'concurrency_limit_exceeded'));
    }

    public function testMapLeavesTheCodeOfAnUnnamedServerErrorNull(): void
    {
        $exception = (new ErrorMapper())->map(new ApiResponseDTO(502, [], []));

        self::assertSame(ProviderException::class, $exception::class);
        self::assertNull($exception->errorCode);
        self::assertSame(502, $exception->statusCode);
        self::assertSame('Slen answered 502.', $exception->getMessage());
    }

    public function testMapKeepsTheMessageAndTheFieldsOfFrameworkValidation(): void
    {
        $exception = (new ErrorMapper())->map(new ApiResponseDTO(422, [], [
            'message' => 'The credits field is required.',
            'errors' => ['credits' => ['The credits field is required.']],
        ]));

        self::assertSame(InvalidRequestException::class, $exception::class);
        self::assertSame('The credits field is required.', $exception->getMessage());
        self::assertSame(['errors' => ['credits' => ['The credits field is required.']]], $exception->details);
        self::assertNull($exception->errorCode);
    }

    public function testMapPrefersTheEnvelopeOverTheFrameworkFields(): void
    {
        $exception = (new ErrorMapper())->map(new ApiResponseDTO(422, [], [
            'error' => ['code' => 'flow_invalid', 'message' => 'The flow is invalid.', 'details' => ['nodes' => ['a1']]],
            'message' => 'ignored',
            'errors' => ['ignored' => ['ignored']],
        ]));

        self::assertSame('The flow is invalid.', $exception->getMessage());
        self::assertSame('flow_invalid', $exception->errorCode);
        self::assertSame(['nodes' => ['a1']], $exception->details);
    }

    public function testMapReadsAMalformedBodyAsNoBody(): void
    {
        $exception = (new ErrorMapper())->map(new ApiResponseDTO(400, [], [
            'error' => ['code' => 42, 'message' => ['x'], 'request_id' => 7, 'details' => 'nope'],
            'errors' => 'nope',
        ]));

        self::assertSame(InvalidRequestException::class, $exception::class);
        self::assertNull($exception->errorCode);
        self::assertNull($exception->requestId);
        self::assertSame([], $exception->details);
        self::assertSame('Slen answered 400.', $exception->getMessage());
    }

    public function testMapTakesTheRequestIdFromTheHeaderBeforeTheBody(): void
    {
        $body = ['error' => ['code' => 'rate_limit_exceeded', 'message' => 'slow down', 'request_id' => 'from-body']];

        $fromHeader = (new ErrorMapper())->map(new ApiResponseDTO(429, ['x-request-id' => 'from-header', 'retry-after' => '12'], $body));
        $fromBody = (new ErrorMapper())->map(new ApiResponseDTO(429, [], $body));

        self::assertSame(RateLimitException::class, $fromHeader::class);
        self::assertSame('from-header', $fromHeader->requestId);
        self::assertSame(12, $fromHeader->retryAfterSeconds);
        self::assertSame('from-body', $fromBody->requestId);
        self::assertNull($fromBody->retryAfterSeconds);
    }

    /**
     * @return Iterator<string, array{string, int, string}>
     */
    public static function codeProvider(): Iterator
    {
        foreach (self::contract()['codes'] as $code => $entry) {
            yield $code => [$code, $entry['status'], $entry['type']];
        }
    }

    /**
     * @return Iterator<string, array{int, string}>
     */
    public static function fallbackProvider(): Iterator
    {
        $fallback = self::contract()['fallback'];

        foreach ($fallback['by_status'] as $status => $type) {
            yield 'status ' . $status => [(int) $status, $type];
        }

        foreach ([500, 502, 503, 504, 599] as $status) {
            yield 'server error ' . $status => [$status, $fallback['server_error']];
        }

        foreach ([400, 404, 409, 410, 422, self::NEUTRAL_STATUS] as $status) {
            yield 'otherwise ' . $status => [$status, $fallback['otherwise']];
        }
    }

    /**
     * @return Iterator<string, array{string, string}>
     */
    public static function typeProvider(): Iterator
    {
        foreach (self::contract()['types'] as $type => $entry) {
            yield $type => [$type, $entry['extends']];
        }
    }

    /**
     * @return Iterator<string, array{string, string}>
     */
    public static function localCodeProvider(): Iterator
    {
        foreach (self::contract()['local'] as $code => $entry) {
            if (\in_array('php', $entry['sdks'], true)) {
                yield $code => [$code, $entry['type']];
            }
        }
    }

    private function refusal(int $status, string $code): SlenException
    {
        return (new ErrorMapper())->map(new ApiResponseDTO($status, [], ['error' => ['code' => $code, 'message' => 'boom']]));
    }

    private static function classOf(string $type): string
    {
        return self::NAMESPACE . $type . 'Exception';
    }

    /**
     * @return array{
     *     types: array<string, array{extends: string}>,
     *     codes: array<string, array{status: int, type: string}>,
     *     fallback: array{by_status: array<string, string>, server_error: string, otherwise: string},
     *     local: array<string, array{type: string, sdks: list<string>}>
     * }
     */
    private static function contract(): array
    {
        /** @var array{types: array<string, array{extends: string}>, codes: array<string, array{status: int, type: string}>, fallback: array{by_status: array<string, string>, server_error: string, otherwise: string}, local: array<string, array{type: string, sdks: list<string>}>} */
        return json_decode((string) file_get_contents(self::CONTRACT), true, 512, JSON_THROW_ON_ERROR);
    }
}
