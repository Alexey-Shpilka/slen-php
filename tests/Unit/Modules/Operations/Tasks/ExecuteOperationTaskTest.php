<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit\Modules\Operations\Tasks;

use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slen\Sdk\Exceptions\InvalidRequestException;
use Slen\Sdk\Modules\Operations\Data\ExecuteRequestDTO;
use Slen\Sdk\Modules\Operations\Options\ExecuteOptions;
use Slen\Sdk\Modules\Operations\Resources\ExecutionResource;
use Slen\Sdk\Modules\Operations\Tasks\ExecuteOperationTask;
use Slen\Sdk\Shared\Http\ApiCaller;
use Slen\Sdk\Shared\Http\ApiTransport;
use Slen\Sdk\Shared\Http\Mappers\ErrorMapper;
use Slen\Sdk\Tests\Unit\Support\ScriptedApiClient;

final class ExecuteOperationTaskTest extends TestCase
{
    public function testTheCustomerAndTheCreditTypeAreClaimsOnTheContext(): void
    {
        $request = $this->task()->request(new ExecuteRequestDTO('support.reply', ['a' => 1], new ExecuteOptions(
            customer: 'user_8421',
            creditType: 'pro',
            context: ['ticket' => 42],
        ), false));

        self::assertSame(
            ['operation' => 'support.reply', 'input' => ['a' => 1], 'context' => ['ticket' => 42, 'user_id' => 'user_8421', 'credit_type' => 'pro']],
            $request->body,
        );
    }

    public function testAContextThatNamesTheSameValueIsTakenWhateverItsType(): void
    {
        $request = $this->task()->request(new ExecuteRequestDTO('a', [], new ExecuteOptions(customer: '42', context: ['user_id' => 42]), false));

        self::assertSame('{"operation":"a","input":{},"context":{"user_id":"42"}}', $request->json);
    }

    public function testAnEmptyContextIsNotSentAndAnAsyncRunSaysSo(): void
    {
        $request = $this->task()->request(new ExecuteRequestDTO('a', [], new ExecuteOptions(), true));

        self::assertSame('{"operation":"a","input":{},"execution_mode":"async"}', $request->json);
    }

    #[DataProvider('claimProvider')]
    public function testAClaimThatCannotBeRightIsRefusedBeforeTheWire(ExecuteOptions $executeOptions, string $message): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage($message);

        $this->task()->request(new ExecuteRequestDTO('a', [], $executeOptions, false));
    }

    /**
     * @return Iterator<string, array{ExecuteOptions, string}>
     */
    public static function claimProvider(): Iterator
    {
        yield 'an empty customer' => [new ExecuteOptions(customer: ''), 'the customer of POST /v1/operations/execute is empty'];
        yield 'a blank credit type' => [new ExecuteOptions(creditType: ' '), 'the creditType of POST /v1/operations/execute is empty'];
        yield 'another customer in the context' => [
            new ExecuteOptions(customer: 'user_1', context: ['user_id' => 'user_2']),
            'customer and context.user_id of POST /v1/operations/execute differ',
        ];
        yield 'another credit type in the context' => [
            new ExecuteOptions(creditType: 'pro', context: ['credit_type' => 'default']),
            'creditType and context.credit_type of POST /v1/operations/execute differ',
        ];
    }

    private function task(): ExecuteOperationTask
    {
        return new ExecuteOperationTask(new ApiCaller(new ApiTransport(new ScriptedApiClient(), new ErrorMapper(), null)), new ExecutionResource());
    }
}
