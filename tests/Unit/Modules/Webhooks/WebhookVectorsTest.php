<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit\Modules\Webhooks;

use DateTimeImmutable;
use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slen\Sdk\Exceptions\InvalidResponseException;
use Slen\Sdk\Exceptions\WebhookSignatureException;
use Slen\Sdk\Modules\Webhooks\Factories\WebhooksModuleFactory;
use Slen\Sdk\Modules\Webhooks\Options\VerifyOptions;
use Slen\Sdk\Modules\Webhooks\Options\WebhooksOptions;
use Slen\Sdk\Modules\Webhooks\WebhooksFacade;
use Slen\Sdk\Shared\Logging\Journal;
use Slen\Sdk\Tests\Paths;
use Slen\Sdk\Tests\Unit\Support\FakeTiming;

/**
 * contract/webhook-vectors.json: signatures made by the backend's own signer, which both SDKs check against.
 *
 * @phpstan-type Event array{id: string, type: string}
 * @phpstan-type Vector array{name: string, payload: string, header: string|null, now: int, valid: bool, reason: string|null, event: Event|null}
 * @phpstan-type Vectors array{secret: string, tolerance_seconds: int, cases: list<Vector>}
 */
final class WebhookVectorsTest extends TestCase
{
    private const string VECTORS = Paths::CONTRACT . '/webhook-vectors.json';

    private const array REASONS = ['missing_header', 'malformed_header', 'outside_tolerance', 'no_matching_signature'];

    /**
     * @param Vector $case
     */
    #[DataProvider('caseProvider')]
    public function testVerifyAnswersWhatTheVectorSays(array $case): void
    {
        self::assertSame($case['valid'], self::facade($case['now'])->verify($case['payload'], $case['header']));
    }

    /**
     * @param Vector $case
     */
    #[DataProvider('caseProvider')]
    public function testConstructEventReadsTheEventOrRefusesForTheReasonOfTheVector(array $case): void
    {
        try {
            $webhookEvent = self::facade($case['now'])->constructEvent($case['payload'], $case['header']);
        } catch (WebhookSignatureException $webhookSignatureException) {
            self::assertFalse($case['valid']);
            self::assertSame($case['reason'], $webhookSignatureException->reason);
            self::assertSame('webhook_signature_invalid', $webhookSignatureException->errorCode);

            return;
        } catch (InvalidResponseException $invalidResponseException) {
            self::assertTrue($case['valid']);
            self::assertNull($case['event']);
            self::assertSame('webhook_payload_invalid', $invalidResponseException->errorCode);

            return;
        }

        self::assertTrue($case['valid']);
        self::assertSame($case['event'], ['id' => $webhookEvent->id, 'type' => $webhookEvent->type]);
    }

    /**
     * @param Vector $case
     */
    #[DataProvider('caseProvider')]
    public function testAReceiverCheckingAtTheMomentOfTheVectorAnswersWhatItSays(array $case): void
    {
        $vectors = self::vectors();
        $receiver = WebhooksFacade::standalone(new WebhooksOptions(secret: $vectors['secret'], toleranceSeconds: $vectors['tolerance_seconds']));
        $atTheVector = new VerifyOptions(now: new DateTimeImmutable('@' . $case['now']));

        self::assertSame($case['valid'], $receiver->verify($case['payload'], $case['header'], $atTheVector));
    }

    public function testTheVectorsHoldEveryReasonAndBodiesThatAreNotEvents(): void
    {
        $cases = self::vectors()['cases'];

        $reasons = array_values(array_unique(array_filter(array_column($cases, 'reason'), static function (?string $reason): bool {
            return $reason !== null;
        })));
        $expected = self::REASONS;
        sort($reasons);
        sort($expected);

        self::assertSame($expected, $reasons);
        self::assertNotSame([], array_filter($cases, static function (array $case): bool {
            return $case['valid'] && $case['event'] === null;
        }));
        self::assertSame(300, self::vectors()['tolerance_seconds']);
    }

    /**
     * @return Iterator<string, array{Vector}>
     */
    public static function caseProvider(): Iterator
    {
        foreach (self::vectors()['cases'] as $case) {
            yield $case['name'] => [$case];
        }
    }

    private static function facade(int $now): WebhooksFacade
    {
        $vectors = self::vectors();

        return new WebhooksFacade(new WebhooksModuleFactory(
            new Journal(new NullLogger()),
            new WebhooksOptions(secret: $vectors['secret'], toleranceSeconds: $vectors['tolerance_seconds']),
            new FakeTiming(unixSeconds: $now),
        ));
    }

    /**
     * @return Vectors
     */
    private static function vectors(): array
    {
        /** @var Vectors */
        return json_decode((string) file_get_contents(self::VECTORS), true, 512, JSON_THROW_ON_ERROR);
    }
}
