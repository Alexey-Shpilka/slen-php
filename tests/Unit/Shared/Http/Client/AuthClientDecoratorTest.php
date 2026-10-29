<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit\Shared\Http\Client;

use PHPUnit\Framework\TestCase;
use Slen\Sdk\Shared\Http\Client\AuthClientDecorator;
use Slen\Sdk\Shared\Http\Data\ApiRequestDTO;
use Slen\Sdk\Shared\Http\Data\ApiResponseDTO;
use Slen\Sdk\Shared\Http\Enums\Endpoint;
use Slen\Sdk\Tests\Unit\Support\ScriptedApiClient;

final class AuthClientDecoratorTest extends TestCase
{
    public function testSendSignsTheRequestAndNamesTheSdk(): void
    {
        $inner = new ScriptedApiClient(new ApiResponseDTO(200));

        (new AuthClientDecorator($inner, 'sk_live_1', 'slen-sdk-php/test'))->send(new ApiRequestDTO(Endpoint::ListPrices));

        self::assertSame(['Authorization' => 'Bearer sk_live_1', 'User-Agent' => 'slen-sdk-php/test'], $inner->requests[0]->headers);
    }

    public function testTheUserAgentNamesThePackageAndTheRuntimeUnlessOneIsGiven(): void
    {
        $inner = new ScriptedApiClient(new ApiResponseDTO(200));

        (new AuthClientDecorator($inner, 'sk_live_1'))->send(new ApiRequestDTO(Endpoint::ListPrices));

        self::assertMatchesRegularExpression('#^slen-sdk-php/\S+ php/' . preg_quote(PHP_VERSION, '#') . '$#', $inner->requests[0]->headers['User-Agent'] ?? '');
    }

    public function testADumpDoesNotShowTheKey(): void
    {
        $decorator = new AuthClientDecorator(new ScriptedApiClient(), 'sk_canary');

        self::assertStringNotContainsString('sk_canary', print_r($decorator, true));
    }
}
