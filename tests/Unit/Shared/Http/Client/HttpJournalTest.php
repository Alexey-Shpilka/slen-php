<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit\Shared\Http\Client;

use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slen\Sdk\Shared\Http\Client\HttpJournal;
use Slen\Sdk\Shared\Http\Data\ApiRequestDTO;
use Slen\Sdk\Shared\Http\Data\ApiResponseDTO;
use Slen\Sdk\Shared\Http\Data\RejectionDTO;
use Slen\Sdk\Shared\Http\Data\RetryDecisionDTO;
use Slen\Sdk\Shared\Http\Enums\Endpoint;
use Slen\Sdk\Shared\Http\Enums\RetryReason;
use Slen\Sdk\Shared\Logging\Journal;
use Slen\Sdk\Tests\Unit\Support\RecordingLogger;

final class HttpJournalTest extends TestCase
{
    private const array WHERE = ['method' => 'POST', 'route' => '/v1/customers/{externalUserId}/charge'];

    public function testRequestNamesTheRouteAndTheAttempt(): void
    {
        [$journal, $logger] = $this->journal();

        $journal->request($this->request()->withAttempt(2));

        self::assertSame(['debug slen.request'], $logger->events());
        self::assertSame([...self::WHERE, 'attempt' => 2], $logger->context('slen.request'));
    }

    public function testResponseCarriesTheStatusTheDurationAndTheRequestId(): void
    {
        [$journal, $logger] = $this->journal();

        $journal->response($this->request(), new ApiResponseDTO(200, ['x-request-id' => 'r1']), 42);

        self::assertSame(['debug slen.response'], $logger->events());
        self::assertSame([...self::WHERE, 'status' => 200, 'duration_ms' => 42, 'request_id' => 'r1'], $logger->context('slen.response'));
    }

    public function testRetryNamesTheAttemptThatFailed(): void
    {
        [$journal, $logger] = $this->journal();

        $journal->retry($this->request()->withAttempt(1), new RetryDecisionDTO(RetryReason::Unavailable, 400), 503);

        self::assertSame(['warning slen.retry'], $logger->events());
        self::assertSame(
            [...self::WHERE, 'attempt' => 1, 'delay_ms' => 400, 'reason' => 'unavailable', 'status' => 503],
            $logger->context('slen.retry'),
        );
    }

    #[DataProvider('rejectedProvider')]
    public function testRejectedTellsAKeyARefusalAndAFailureApart(?int $status, ?string $code, string $event): void
    {
        [$journal, $logger] = $this->journal();

        $journal->rejected($this->request(), new RejectionDTO($status, $code, 'r1', 'Type', 3));

        self::assertSame([$event], $logger->events());
    }

    /**
     * @return Iterator<string, array{?int, ?string, string}>
     */
    public static function rejectedProvider(): Iterator
    {
        yield '401' => [401, 'invalid_api_key', 'error slen.unauthorized'];
        yield '401 without a code' => [401, null, 'error slen.unauthorized'];
        yield '403 insufficient_scope' => [403, 'insufficient_scope', 'error slen.unauthorized'];
        yield '403 invalid_api_key' => [403, 'invalid_api_key', 'error slen.unauthorized'];
        yield '403 not_a_member' => [403, 'not_a_member', 'error slen.unauthorized'];
        yield '403 without a code' => [403, null, 'error slen.unauthorized'];
        yield '403 policy_denied' => [403, 'policy_denied', 'info slen.refused'];
        yield '402' => [402, 'insufficient_credits', 'info slen.refused'];
        yield '404' => [404, 'customer_not_found', 'info slen.refused'];
        yield '429' => [429, 'rate_limit_exceeded', 'info slen.refused'];
        yield '500' => [500, 'flow_runtime_error', 'error slen.failed'];
        yield '503' => [503, null, 'error slen.failed'];
        yield 'an unreadable 200' => [200, 'invalid_response', 'error slen.failed'];
        yield 'no answer' => [null, null, 'error slen.failed'];
    }

    public function testRejectedWritesTheFieldsOfEachEvent(): void
    {
        [$journal, $logger] = $this->journal();

        $journal->rejected($this->request(), new RejectionDTO(402, 'insufficient_credits', 'r1', 'InsufficientCredits', 1));
        $journal->rejected($this->request(), new RejectionDTO(403, null, 'r2', 'Authentication', 1));
        $journal->rejected($this->request(), new RejectionDTO(null, null, null, 'Timeout', 3));

        self::assertSame(
            [...self::WHERE, 'status' => 402, 'error_code' => 'insufficient_credits', 'request_id' => 'r1'],
            $logger->context('slen.refused'),
        );
        self::assertSame([...self::WHERE, 'status' => 403, 'error_code' => null, 'request_id' => 'r2'], $logger->context('slen.unauthorized'));
        self::assertSame(
            [...self::WHERE, 'status' => null, 'error_code' => null, 'error_type' => 'Timeout', 'request_id' => null, 'attempts' => 3],
            $logger->context('slen.failed'),
        );
    }

    /**
     * @return array{HttpJournal, RecordingLogger}
     */
    private function journal(): array
    {
        $logger = new RecordingLogger();

        return [new HttpJournal(new Journal($logger)), $logger];
    }

    private function request(): ApiRequestDTO
    {
        return new ApiRequestDTO(Endpoint::Charge, ['externalUserId' => 'jane@example.com'], body: ['credits' => '1.0000']);
    }
}
