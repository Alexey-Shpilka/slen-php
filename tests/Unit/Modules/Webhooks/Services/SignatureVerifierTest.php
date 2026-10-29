<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit\Modules\Webhooks\Services;

use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slen\Sdk\Modules\Webhooks\Services\SignatureVerifier;

final class SignatureVerifierTest extends TestCase
{
    private const string SECRET = 'whsec_test';

    private const string BODY = '{"id":"evt_1"}';

    private const int NOW = 1_760_000_000;

    #[DataProvider('headerProvider')]
    public function testTheHeaderIsReadAsOneTimeAndAnyNumberOfSignatures(?string $header, ?string $reason): void
    {
        $signatureCheckDTO = (new SignatureVerifier(300, self::NOW))->check(self::BODY, $header, self::SECRET);

        self::assertSame($reason, $signatureCheckDTO->reason);
    }

    /**
     * @return Iterator<string, array{?string, ?string}>
     */
    public static function headerProvider(): Iterator
    {
        $good = self::sign(self::NOW);

        yield 'one signature' => ['t=' . self::NOW . ',v1=' . $good, null];
        yield 'a secret being rotated' => ['t=' . self::NOW . ',v1=' . str_repeat('0', 64) . ',v1=' . $good, null];
        yield 'another scheme beside it' => ['t=' . self::NOW . ',v0=abc,v1=' . $good, null];
        yield 'spaces around the pairs' => [' t=' . self::NOW . ' , v1=' . $good . ' ', null];
        yield 'no header' => [null, 'missing_header'];
        yield 'a blank header' => ['  ', 'missing_header'];
        yield 'no time' => ['v1=' . $good, 'malformed_header'];
        yield 'two times' => ['t=' . self::NOW . ',t=' . self::NOW . ',v1=' . $good, 'malformed_header'];
        yield 'a time that is not digits' => ['t=-1,v1=' . $good, 'malformed_header'];
        yield 'no signature' => ['t=' . self::NOW, 'malformed_header'];
        yield 'pairs without a value' => ['t,v1', 'malformed_header'];
        yield 'a signature of another body' => ['t=' . self::NOW . ',v1=' . hash_hmac('sha256', self::NOW . '.{}', self::SECRET), 'no_matching_signature'];
    }

    public function testATimeFurtherFromTheMomentThanTheToleranceIsRefusedWithItsAge(): void
    {
        $then = self::NOW - 301;
        $signatureCheckDTO = (new SignatureVerifier(300, self::NOW))->check(self::BODY, 't=' . $then . ',v1=' . self::sign($then), self::SECRET);

        self::assertSame('outside_tolerance', $signatureCheckDTO->reason);
        self::assertSame(301, $signatureCheckDTO->ageSeconds);
    }

    private static function sign(int $at): string
    {
        return hash_hmac('sha256', $at . '.' . self::BODY, self::SECRET);
    }
}
