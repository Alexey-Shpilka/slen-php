<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit\Modules\Webhooks;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Slen\Sdk\Exceptions\InvalidResponseException;
use Slen\Sdk\Exceptions\WebhookSignatureException;
use Slen\Sdk\Modules\Operations\Enums\ExecutionStatus;
use Slen\Sdk\Modules\Webhooks\Data\ApprovalDecidedEventDTO;
use Slen\Sdk\Modules\Webhooks\Data\ApprovalRequestedEventDTO;
use Slen\Sdk\Modules\Webhooks\Data\CustomerBalanceLowEventDTO;
use Slen\Sdk\Modules\Webhooks\Data\ExecutionFinishedEventDTO;
use Slen\Sdk\Modules\Webhooks\Data\UnknownEventDTO;
use Slen\Sdk\Modules\Webhooks\Data\WebhookEventDTO;
use Slen\Sdk\Modules\Webhooks\Enums\WebhookEventType;
use Slen\Sdk\Modules\Webhooks\Factories\WebhooksModuleFactory;
use Slen\Sdk\Modules\Webhooks\Options\VerifyOptions;
use Slen\Sdk\Modules\Webhooks\Options\WebhooksOptions;
use Slen\Sdk\Modules\Webhooks\WebhooksFacade;
use Slen\Sdk\Shared\Logging\Journal;
use Slen\Sdk\SlenConfig;
use Slen\Sdk\SlenSdk;
use Slen\Sdk\Testing\FakeHttpClient;
use Slen\Sdk\Tests\Unit\Support\FakeTiming;
use Slen\Sdk\Tests\Unit\Support\RecordingLogger;

final class WebhooksFacadeTest extends TestCase
{
    private const string SECRET = 'whsec_canary_9d0e1f';

    private const int NOW = 1_792_238_400;

    private RecordingLogger $recordingLogger;

    protected function setUp(): void
    {
        $this->recordingLogger = new RecordingLogger();
    }

    public function testAnExecutionThatFinishedIsReadIntoItsType(): void
    {
        $data = ['execution_id' => '7', 'flow_id' => '8', 'operation' => 'support.reply', 'status' => 'rejected', 'error_code' => 'budget_exceeded'];

        $event = $this->construct('execution.finished', $data);

        self::assertInstanceOf(ExecutionFinishedEventDTO::class, $event);
        self::assertSame(['7', '8', 'support.reply', ExecutionStatus::Rejected, 'budget_exceeded'], [
            $event->executionId,
            $event->flowId,
            $event->operationCode,
            $event->status,
            $event->errorCode,
        ]);
        self::assertSame('evt_1', $event->id);
        self::assertSame(WebhookEventType::ExecutionFinished, $event->eventType());
        self::assertEquals(new DateTimeImmutable('2026-10-17T12:00:00+00:00'), $event->createdAt);
        self::assertSame($data, $event->data);
    }

    public function testALowBalanceKeepsItsAmountsAsDecimalStrings(): void
    {
        $event = $this->construct('customer.balance_low', [
            'customer_account_id' => '11',
            'project_id' => '12',
            'external_user_id' => 'ada@example.com',
            'credit_type' => 'default',
            'balance' => '4.5000',
            'reserved' => '0.5000',
            'available' => '4.0000',
            'threshold' => '5.0000',
        ]);

        self::assertInstanceOf(CustomerBalanceLowEventDTO::class, $event);
        self::assertSame(['ada@example.com', 'default', '4.5000', '0.5000', '4.0000', '5.0000'], [
            $event->externalUserId,
            $event->creditType,
            $event->balance,
            $event->reserved,
            $event->available,
            $event->threshold,
        ]);
    }

    public function testAnApprovalRequestedAndDecidedAreReadIntoTheirTypes(): void
    {
        $approval = ['approval_id' => '21', 'execution_id' => '7', 'flow_id' => '8', 'node_key' => 'approve_refund', 'operation_code' => 'support.refund'];

        $requested = $this->construct('approval.requested', $approval + ['note' => 'Повернення — перевірте ✓', 'expires_at' => '2026-10-18T12:00:00+00:00']);
        $decided = $this->construct('approval.decided', $approval + [
            'status' => 'expired',
            'reason' => null,
            'decided_by' => null,
            'decided_by_user_id' => null,
            'decided_at' => null,
        ]);

        self::assertInstanceOf(ApprovalRequestedEventDTO::class, $requested);
        self::assertSame('Повернення — перевірте ✓', $requested->note);
        self::assertEquals(new DateTimeImmutable('2026-10-18T12:00:00+00:00'), $requested->expiresAt);
        self::assertInstanceOf(ApprovalDecidedEventDTO::class, $decided);
        self::assertSame(['support.refund', 'expired', null, null], [$decided->operationCode, $decided->status, $decided->decidedAt, $decided->decidedBy]);
    }

    public function testAnEventWithoutAClassAndATypeTheSdkDoesNotKnowAreBothOther(): void
    {
        $published = $this->construct('flow.published', ['flow_id' => '8', 'version' => 7]);
        $newer = $this->construct('invoice.paid', ['invoice' => 'INV-1']);

        self::assertInstanceOf(UnknownEventDTO::class, $published);
        self::assertSame(WebhookEventType::FlowPublished, $published->eventType());
        self::assertSame(['flow_id' => '8', 'version' => 7], $published->data);
        self::assertInstanceOf(UnknownEventDTO::class, $newer);
        self::assertSame('invoice.paid', $newer->type);
        self::assertNull($newer->eventType());
    }

    public function testATypedEventWhoseDataIsNotItsShapeIsNotAnEvent(): void
    {
        try {
            $this->construct('execution.finished', ['execution_id' => '7', 'flow_id' => '8', 'operation' => 'a', 'status' => 'paused', 'error_code' => null]);
            self::fail('a status the SDK does not know was read');
        } catch (InvalidResponseException $invalidResponseException) {
            self::assertSame('webhook_payload_invalid', $invalidResponseException->errorCode);
            self::assertInstanceOf(InvalidResponseException::class, $invalidResponseException->getPrevious());
        }

        self::assertSame(['warning slen.webhook.rejected'], $this->recordingLogger->events());
        self::assertSame(['reason' => 'invalid_payload', 'age_seconds' => 0], $this->recordingLogger->records[0]['context']);
    }

    public function testARefusalIsInTheJournalWithItsReasonAndAgeAndNothingOfTheWebhook(): void
    {
        $body = $this->body('execution.finished', ['note' => 'canary-body-7731']);
        $header = $this->sign($body, self::NOW - 400);

        self::assertFalse($this->facade()->verify($body, $header));

        try {
            $this->facade()->constructEvent($body, null);
            self::fail('a webhook without a signature was read');
        } catch (WebhookSignatureException $webhookSignatureException) {
            self::assertSame('missing_header', $webhookSignatureException->reason);
            self::assertSame('Slen: the webhook has no Slen-Signature header.', $webhookSignatureException->getMessage());
        }

        self::assertSame(['warning slen.webhook.rejected', 'warning slen.webhook.rejected'], $this->recordingLogger->events());
        self::assertSame(['reason' => 'outside_tolerance', 'age_seconds' => 400], $this->recordingLogger->records[0]['context']);
        self::assertSame(['reason' => 'missing_header', 'age_seconds' => null], $this->recordingLogger->records[1]['context']);

        $journal = json_encode($this->recordingLogger->records, JSON_THROW_ON_ERROR);

        foreach (['canary-body-7731', self::SECRET, substr($header, -64)] as $secret) {
            self::assertStringNotContainsString($secret, $journal);
        }
    }

    public function testTheSecretOfTheCallWinsOverTheOneOfTheEndpoint(): void
    {
        $body = $this->body('flow.published', []);
        $facade = $this->facade('whsec_of_the_endpoint');

        self::assertTrue($facade->verify($body, $this->sign($body), new VerifyOptions(self::SECRET)));
        self::assertFalse($facade->verify($body, $this->sign($body)));
        self::assertTrue($facade->verify($body, $this->sign($body, secret: 'whsec_of_the_endpoint')));
    }

    public function testABlankSecretInTheCallIsRefusedRatherThanSwappedForTheEndpointsOne(): void
    {
        $body = $this->body('flow.published', []);

        try {
            $this->facade()->constructEvent($body, $this->sign($body), new VerifyOptions(' '));
            self::fail('a blank secret was taken');
        } catch (InvalidArgumentException $invalidArgumentException) {
            self::assertStringContainsString('the webhook secret passed is empty', $invalidArgumentException->getMessage());
        }

        self::assertSame([], $this->recordingLogger->records);
    }

    public function testAnEmptyDataIsAnEmptyObjectAndAnEventWithoutItsTimeIsNot(): void
    {
        $body = '{"id":"evt_2","type":"ledger.degraded","created_at":"2026-10-17T12:00:00+00:00","data":[]}';
        $untimed = '{"id":"evt_3","type":"ledger.degraded","data":{}}';

        self::assertSame([], $this->facade()->constructEvent($body, $this->sign($body))->data);

        $this->expectException(InvalidResponseException::class);

        $this->facade()->constructEvent($untimed, $this->sign($untimed));
    }

    public function testWithNoSecretAnywhereNothingIsCheckedAndNothingWritten(): void
    {
        try {
            $this->facade(null)->verify('{}', 't=1,v1=00');
            self::fail('a webhook was checked without a secret');
        } catch (InvalidArgumentException $invalidArgumentException) {
            self::assertStringContainsString('signing secret of its endpoint', $invalidArgumentException->getMessage());
        }

        self::assertSame([], $this->recordingLogger->records);
    }

    public function testAReceiverWithoutAnApiKeyChecksWebhooksOnItsOwn(): void
    {
        $standalone = WebhooksFacade::standalone(new WebhooksOptions(secret: self::SECRET));
        $body = $this->body('flow.published', []);
        $now = (string) time();

        self::assertTrue($standalone->verify($body, 't=' . $now . ',v1=' . hash_hmac('sha256', $now . '.' . $body, self::SECRET)));
        self::assertStringNotContainsString(self::SECRET, print_r($standalone, true));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the webhook tolerance must be 1 second or more, got 0');

        WebhooksFacade::standalone(new WebhooksOptions(toleranceSeconds: 0));
    }

    public function testTheSdkChecksWithTheSecretAndTheToleranceOfItsConfig(): void
    {
        $slenConfig = new SlenConfig('sk_test_1', webhookSecret: self::SECRET, webhookToleranceSeconds: 5);
        $slenSdk = SlenSdk::create($slenConfig->withHttpClient(new FakeHttpClient()));
        $body = $this->body('flow.published', []);
        $now = time();

        self::assertSame($slenSdk->webhooks(), $slenSdk->webhooks());
        self::assertTrue($slenSdk->webhooks()->verify($body, 't=' . $now . ',v1=' . hash_hmac('sha256', $now . '.' . $body, self::SECRET)));
        self::assertFalse($slenSdk->webhooks()->verify($body, 't=' . ($now - 60) . ',v1=' . hash_hmac('sha256', ($now - 60) . '.' . $body, self::SECRET)));
    }

    public function testTheToleranceAndTheMomentOfACallReplaceTheReceiversOwn(): void
    {
        $body = $this->body('flow.published', []);
        $minuteOld = $this->sign($body, self::NOW - 60);

        self::assertTrue($this->facade()->verify($body, $minuteOld));
        self::assertFalse($this->facade()->verify($body, $minuteOld, new VerifyOptions(toleranceSeconds: 30)));
        self::assertTrue($this->facade()->verify($body, $minuteOld, new VerifyOptions(now: new DateTimeImmutable('@' . (self::NOW - 60)))));
        self::assertFalse($this->facade()->verify($body, $this->sign($body), new VerifyOptions(now: new DateTimeImmutable('@' . (self::NOW + 600)))));
        self::assertSame(['outside_tolerance', 'outside_tolerance'], array_column(array_column($this->recordingLogger->records, 'context'), 'reason'));
    }

    public function testATolerancePassedBelowOneSecondIsRefusedAndNothingIsWritten(): void
    {
        $body = $this->body('flow.published', []);

        try {
            $this->facade()->constructEvent($body, $this->sign($body), new VerifyOptions(toleranceSeconds: 0));
            self::fail('a tolerance of zero was taken');
        } catch (InvalidArgumentException $invalidArgumentException) {
            self::assertStringContainsString('the webhook tolerance must be 1 second or more, got 0', $invalidArgumentException->getMessage());
        }

        self::assertSame([], $this->recordingLogger->records);
    }

    public function testNoDumpOfTheOptionsCarriesTheSecret(): void
    {
        $verifyOptions = new VerifyOptions(self::SECRET, 30);
        $webhooksOptions = new WebhooksOptions(secret: self::SECRET);

        self::assertSame(self::SECRET, $verifyOptions->secret());
        self::assertSame(self::SECRET, $webhooksOptions->secret());
        self::assertStringNotContainsString(self::SECRET, print_r($verifyOptions, true) . print_r($webhooksOptions, true));
        self::assertStringNotContainsString(self::SECRET, json_encode([$verifyOptions, $webhooksOptions], JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function construct(string $type, array $data): WebhookEventDTO
    {
        $body = $this->body($type, $data);

        return $this->facade()->constructEvent($body, $this->sign($body));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function body(string $type, array $data): string
    {
        return json_encode(
            ['id' => 'evt_1', 'type' => $type, 'created_at' => '2026-10-17T12:00:00+00:00', 'data' => $data],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    private function sign(string $body, int $at = self::NOW, string $secret = self::SECRET): string
    {
        return 't=' . $at . ',v1=' . hash_hmac('sha256', $at . '.' . $body, $secret);
    }

    private function facade(?string $secret = self::SECRET): WebhooksFacade
    {
        return new WebhooksFacade(new WebhooksModuleFactory(
            new Journal($this->recordingLogger),
            new WebhooksOptions(secret: $secret, toleranceSeconds: 300),
            new FakeTiming(unixSeconds: self::NOW),
        ));
    }
}
