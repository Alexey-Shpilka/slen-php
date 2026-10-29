<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit\Shared\Http\Client;

use PHPUnit\Framework\TestCase;
use Slen\Sdk\Exceptions\ConnectionException;
use Slen\Sdk\Shared\Http\Client\HttpJournal;
use Slen\Sdk\Shared\Http\Client\LoggingClientDecorator;
use Slen\Sdk\Shared\Http\Data\ApiRequestDTO;
use Slen\Sdk\Shared\Http\Data\ApiResponseDTO;
use Slen\Sdk\Shared\Http\Enums\Endpoint;
use Slen\Sdk\Shared\Http\Mappers\ErrorMapper;
use Slen\Sdk\Shared\Logging\Journal;
use Slen\Sdk\Tests\Unit\Support\RecordingLogger;
use Slen\Sdk\Tests\Unit\Support\ScriptedApiClient;

final class LoggingClientDecoratorTest extends TestCase
{
    public function testSendWritesAnAnswerAndHandsItBackUnchanged(): void
    {
        $logger = new RecordingLogger();
        $answer = new ApiResponseDTO(200, ['x-request-id' => 'r1'], ['data' => []]);

        $response = $this->decorator(new ScriptedApiClient($answer), $logger)->send($this->request());

        self::assertSame(200, $response->status);
        self::assertSame(['data' => []], $response->body);
        self::assertSame(['debug slen.response'], $logger->events());
    }

    public function testSendWritesAnUnreadableSuccessAsAFailure(): void
    {
        $logger = new RecordingLogger();

        $this->decorator(new ScriptedApiClient(new ApiResponseDTO(200, [], [], false)), $logger)->send($this->request());

        self::assertSame(['error slen.failed'], $logger->events());
        self::assertSame('InvalidResponse', $logger->context('slen.failed')['error_type']);
        self::assertSame('invalid_response', $logger->context('slen.failed')['error_code']);
    }

    public function testSendNamesTheTypeOfTheRefusalThatEndedTheCall(): void
    {
        $logger = new RecordingLogger();

        $this->decorator(new ScriptedApiClient(new ApiResponseDTO(502)), $logger)->send($this->request());
        $this->decorator(new ScriptedApiClient(new ApiResponseDTO(403)), $logger)->send($this->request());

        self::assertSame(['error slen.failed', 'error slen.unauthorized'], $logger->events());
        self::assertSame('Provider', $logger->context('slen.failed')['error_type']);
        self::assertNull($logger->context('slen.failed')['error_code']);
    }

    public function testSendLeavesNoAnswerToTheRetry(): void
    {
        $logger = new RecordingLogger();

        try {
            $this->decorator(new ScriptedApiClient(new ConnectionException('x')), $logger)->send($this->request());
            self::fail('Expected ConnectionException');
        } catch (ConnectionException) {
            self::assertSame([], $logger->records);
        }
    }

    private function decorator(ScriptedApiClient $inner, RecordingLogger $logger): LoggingClientDecorator
    {
        return new LoggingClientDecorator($inner, new HttpJournal(new Journal($logger)), new ErrorMapper());
    }

    private function request(): ApiRequestDTO
    {
        return new ApiRequestDTO(Endpoint::ListCustomers);
    }
}
