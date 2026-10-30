<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit\Shared\Http;

use PHPUnit\Framework\TestCase;
use Slen\Sdk\Exceptions\InsufficientCreditsException;
use Slen\Sdk\Exceptions\InvalidResponseException;
use Slen\Sdk\Shared\Http\ApiCaller;
use Slen\Sdk\Shared\Http\ApiTransport;
use Slen\Sdk\Shared\Http\Client\ApiClientInterface;
use Slen\Sdk\Shared\Http\Data\ApiRequestDTO;
use Slen\Sdk\Shared\Http\Data\ApiResponseDTO;
use Slen\Sdk\Shared\Http\Enums\Endpoint;
use Slen\Sdk\Shared\Http\Mappers\ErrorMapper;
use Slen\Sdk\Tests\Unit\Support\ScriptedApiClient;

final class ApiCallerTest extends TestCase
{
    public function testDataHandsBackTheDataOfAnEnvelope(): void
    {
        $apiCaller = $this->apiCaller(new ScriptedApiClient(new ApiResponseDTO(200, [], ['data' => ['id' => 'e1'], 'meta' => []])));

        self::assertSame(['id' => 'e1'], $apiCaller->data(new ApiRequestDTO(Endpoint::ListPrices)));
    }

    public function testDataHandsBackTheWholeBodyOfAnAnswerWithoutEnvelope(): void
    {
        $apiCaller = $this->apiCaller(new ScriptedApiClient(new ApiResponseDTO(200, [], ['app' => 'slen'])));

        self::assertSame(['app' => 'slen'], $apiCaller->data(new ApiRequestDTO(Endpoint::Health, envelope: false)));
    }

    public function testExchangeThrowsARefusalAsItsType(): void
    {
        $refusal = new ApiResponseDTO(402, ['x-request-id' => 'r9'], ['error' => ['code' => 'insufficient_credits', 'message' => 'no']]);
        $apiCaller = $this->apiCaller(new ScriptedApiClient($refusal));

        try {
            $apiCaller->exchange(new ApiRequestDTO(Endpoint::ListPrices));
            self::fail('Expected InsufficientCreditsException');
        } catch (InsufficientCreditsException $insufficientCreditsException) {
            self::assertSame('r9', $insufficientCreditsException->requestId);
            self::assertSame(402, $insufficientCreditsException->statusCode);
        }
    }

    public function testExchangeHandsBackTheAnswerOfASuccess(): void
    {
        $apiCaller = $this->apiCaller(new ScriptedApiClient(new ApiResponseDTO(204)));

        self::assertSame(204, $apiCaller->exchange(new ApiRequestDTO(Endpoint::ListPrices))->status);
    }

    public function testExchangeRefusesASuccessItCannotRead(): void
    {
        $apiCaller = $this->apiCaller(new ScriptedApiClient(new ApiResponseDTO(200, ['x-request-id' => 'r7'], [], false)));

        try {
            $apiCaller->exchange(new ApiRequestDTO(Endpoint::GetCustomer, ['externalUserId' => 'jane@example.com']));
            self::fail('Expected InvalidResponseException');
        } catch (InvalidResponseException $invalidResponseException) {
            self::assertSame('invalid_response', $invalidResponseException->errorCode);
            self::assertSame(200, $invalidResponseException->statusCode);
            self::assertSame('r7', $invalidResponseException->requestId);
            self::assertStringContainsString('GET /v1/customers/{externalUserId}', $invalidResponseException->getMessage());
            self::assertStringNotContainsString('jane', $invalidResponseException->getMessage());
        }
    }

    private function apiCaller(ApiClientInterface $client): ApiCaller
    {
        return new ApiCaller(new ApiTransport($client, new ErrorMapper(), null));
    }
}
