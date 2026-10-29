<?php

declare(strict_types=1);

namespace Slen\Sdk\Modules\Webhooks\Services;

use Slen\Sdk\Modules\Webhooks\Data\SignatureCheckDTO;

/** @internal */
final readonly class SignatureVerifier
{
    public const string MISSING_HEADER = 'missing_header';

    public const string MALFORMED_HEADER = 'malformed_header';

    public const string OUTSIDE_TOLERANCE = 'outside_tolerance';

    public const string NO_MATCHING_SIGNATURE = 'no_matching_signature';

    public function __construct(
        private int $toleranceSeconds,
        private int $now,
    ) {
    }

    public function check(string $payload, ?string $signatureHeader, #[\SensitiveParameter] string $secret): SignatureCheckDTO
    {
        if ($signatureHeader === null || trim($signatureHeader) === '') {
            return new SignatureCheckDTO(self::MISSING_HEADER, null);
        }

        $parsed = $this->parse($signatureHeader);

        if ($parsed === null) {
            return new SignatureCheckDTO(self::MALFORMED_HEADER, null);
        }

        [$timestamp, $signatures] = $parsed;
        $age = $this->now - $timestamp;

        if (abs($age) > $this->toleranceSeconds) {
            return new SignatureCheckDTO(self::OUTSIDE_TOLERANCE, $age);
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature) === true) {
                return new SignatureCheckDTO(null, $age);
            }
        }

        return new SignatureCheckDTO(self::NO_MATCHING_SIGNATURE, $age);
    }

    /** @return array{int, list<string>}|null */
    private function parse(string $signatureHeader): ?array
    {
        $pairs = ['t' => [], 'v1' => []];

        foreach (explode(',', $signatureHeader) as $part) {
            $pair = explode('=', trim($part), 2);

            if (\count($pair) === 2 && isset($pairs[$pair[0]]) === true) {
                $pairs[$pair[0]][] = $pair[1];
            }
        }

        $timestamps = $pairs['t'];
        $signatures = $pairs['v1'];

        if (\count($timestamps) !== 1 || ctype_digit($timestamps[0]) === false || $signatures === []) {
            return null;
        }

        return [(int) $timestamps[0], $signatures];
    }
}
