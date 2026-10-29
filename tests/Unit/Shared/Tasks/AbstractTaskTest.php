<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit\Shared\Tasks;

use PHPUnit\Framework\TestCase;
use Slen\Sdk\Exceptions\InsufficientCreditsException;
use Slen\Sdk\Exceptions\InvalidResponseException;
use Slen\Sdk\Shared\Http\ApiTransport;
use Slen\Sdk\Shared\Http\Client\ApiClientInterface;
use Slen\Sdk\Shared\Http\Data\ApiRequestDTO;
use Slen\Sdk\Shared\Http\Data\ApiResponseDTO;
use Slen\Sdk\Shared\Http\Enums\Endpoint;
use Slen\Sdk\Shared\Http\Mappers\ErrorMapper;
use Slen\Sdk\Tests\Unit\Support\ExposedTask;
use Slen\Sdk\Tests\Unit\Support\ScriptedApiClient;

final class AbstractTaskTest extends TestCase
{
    public function testSendHandsBackTheDataOfAnEnvelope(): void
    {
        $task = $this->task(new ScriptedApiClient(new ApiResponseDTO(200, [], ['data' => ['id' => 'e1'], 'meta' => []])));

        self::assertSame(['id' => 'e1'], $task->sendNow(new ApiRequestDTO(Endpoint::ListPrices)));
    }

    public function testSendHandsBackTheWholeBodyOfAnAnswerWithoutEnvelope(): void
    {
        $task = $this->task(new ScriptedApiClient(new ApiResponseDTO(200, [], ['app' => 'slen'])));

        self::assertSame(['app' => 'slen'], $task->sendNow(new ApiRequestDTO(Endpoint::Health, envelope: false)));
    }

    public function testExchangeThrowsARefusalAsItsType(): void
    {
        $refusal = new ApiResponseDTO(402, ['x-request-id' => 'r9'], ['error' => ['code' => 'insufficient_credits', 'message' => 'no']]);
        $task = $this->task(new ScriptedApiClient($refusal));

        try {
            $task->exchangeNow(new ApiRequestDTO(Endpoint::ListPrices));
            self::fail('Expected InsufficientCreditsException');
        } catch (InsufficientCreditsException $insufficientCreditsException) {
            self::assertSame('r9', $insufficientCreditsException->requestId);
            self::assertSame(402, $insufficientCreditsException->statusCode);
        }
    }

    public function testExchangeHandsBackTheAnswerOfASuccess(): void
    {
        $task = $this->task(new ScriptedApiClient(new ApiResponseDTO(204)));

        self::assertSame(204, $task->exchangeNow(new ApiRequestDTO(Endpoint::ListPrices))->status);
    }

    public function testExchangeRefusesASuccessItCannotRead(): void
    {
        $task = $this->task(new ScriptedApiClient(new ApiResponseDTO(200, ['x-request-id' => 'r7'], [], false)));

        try {
            $task->exchangeNow(new ApiRequestDTO(Endpoint::GetCustomer, ['externalUserId' => 'jane@example.com']));
            self::fail('Expected InvalidResponseException');
        } catch (InvalidResponseException $invalidResponseException) {
            self::assertSame('invalid_response', $invalidResponseException->errorCode);
            self::assertSame(200, $invalidResponseException->statusCode);
            self::assertSame('r7', $invalidResponseException->requestId);
            self::assertStringContainsString('GET /v1/customers/{externalUserId}', $invalidResponseException->getMessage());
            self::assertStringNotContainsString('jane', $invalidResponseException->getMessage());
        }
    }

    public function testIdempotencyKeyKeepsTheCallersAndMakesOneOtherwise(): void
    {
        $task = $this->task(new ScriptedApiClient());

        self::assertSame('invoice-7781:topup', $task->keyFor('invoice-7781:topup'));
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $task->keyFor(null));
    }

    private function task(ApiClientInterface $client): ExposedTask
    {
        return new ExposedTask(new ApiTransport($client, new ErrorMapper(), null));
    }
}
