<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit\Modules\Customers\Tasks;

use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slen\Sdk\Exceptions\InvalidRequestException;
use Slen\Sdk\Modules\Customers\Data\CreditMovementDTO;
use Slen\Sdk\Modules\Customers\Enums\CreditMovement;
use Slen\Sdk\Modules\Customers\Options\MovementOptions;
use Slen\Sdk\Modules\Customers\Resources\CustomerAccountResource;
use Slen\Sdk\Modules\Customers\Tasks\MoveCreditsTask;
use Slen\Sdk\Shared\Http\ApiTransport;
use Slen\Sdk\Shared\Http\Data\ApiResponseDTO;
use Slen\Sdk\Shared\Http\Enums\Endpoint;
use Slen\Sdk\Shared\Http\Mappers\ErrorMapper;
use Slen\Sdk\Tests\Unit\Support\CustomerAnswers;
use Slen\Sdk\Tests\Unit\Support\ScriptedApiClient;

final class MoveCreditsTaskTest extends TestCase
{
    #[DataProvider('amountProvider')]
    public function testAMovementSendsTheAmountItIsGivenAsItIs(CreditMovement $creditMovement, string $credits): void
    {
        $scriptedApiClient = new ScriptedApiClient(new ApiResponseDTO(200, [], ['data' => CustomerAnswers::account()]));

        $this->task($scriptedApiClient)->execute(new CreditMovementDTO($creditMovement, 'user_8421', $credits, new MovementOptions()));

        self::assertSame($creditMovement->endpoint(), $scriptedApiClient->requests[0]->endpoint);
        self::assertSame(['credits' => $credits, 'credit_type' => 'default'], $scriptedApiClient->requests[0]->body);
    }

    /**
     * @return Iterator<string, array{CreditMovement, string}>
     */
    public static function amountProvider(): Iterator
    {
        yield 'a whole top-up' => [CreditMovement::TopUp, '5'];
        yield 'a top-up of four places' => [CreditMovement::TopUp, '100.0000'];
        yield 'a charge of two places' => [CreditMovement::Charge, '5.25'];
        yield 'a negative correction' => [CreditMovement::Adjust, '-3.0000'];
        yield 'a positive correction' => [CreditMovement::Adjust, '0.0001'];
    }

    #[DataProvider('refusalProvider')]
    public function testAnAmountTheMovementCannotTakeIsRefusedBeforeTheWire(CreditMovement $creditMovement, string $credits, string $message): void
    {
        $scriptedApiClient = new ScriptedApiClient();

        try {
            $this->task($scriptedApiClient)->execute(new CreditMovementDTO($creditMovement, 'user_8421', $credits, new MovementOptions()));
            self::fail('the amount was taken');
        } catch (InvalidRequestException $invalidRequestException) {
            self::assertSame('invalid_request', $invalidRequestException->errorCode);
            self::assertStringContainsString($message, $invalidRequestException->getMessage());
        }

        self::assertSame([], $scriptedApiClient->requests);
    }

    /**
     * @return Iterator<string, array{CreditMovement, string, string}>
     */
    public static function refusalProvider(): Iterator
    {
        $notAnAmount = 'are not a decimal string of at most four places';

        yield 'five places' => [CreditMovement::TopUp, '1.00001', $notAnAmount];
        yield 'a comma' => [CreditMovement::TopUp, '1,5', $notAnAmount];
        yield 'a plus' => [CreditMovement::Charge, '+1', $notAnAmount];
        yield 'nothing' => [CreditMovement::Adjust, '', $notAnAmount];
        yield 'an exponent' => [CreditMovement::TopUp, '1e3', $notAnAmount];
        yield 'a top-up of zero' => [CreditMovement::TopUp, '0.0000', 'are not above zero'];
        yield 'a negative charge' => [CreditMovement::Charge, '-1.5', 'are not above zero'];
        yield 'a charge of minus zero' => [CreditMovement::Charge, '-0', 'are not above zero'];
        yield 'a correction of zero' => [CreditMovement::Adjust, '-0.00', 'are zero; a correction moves something'];
    }

    public function testAMovementNamesItsAccountAndItsNoteAndRefusesABlankAccount(): void
    {
        $scriptedApiClient = new ScriptedApiClient(new ApiResponseDTO(200, [], ['data' => CustomerAnswers::account()]));
        $task = $this->task($scriptedApiClient);

        $task->execute(new CreditMovementDTO(CreditMovement::Charge, 'user_8421', '1', new MovementOptions('search', 'PDF export', 'export-1:charge')));

        self::assertSame(['credits' => '1', 'credit_type' => 'search', 'note' => 'PDF export'], $scriptedApiClient->requests[0]->body);
        self::assertSame('export-1:charge', $scriptedApiClient->requests[0]->idempotencyKey);

        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('the creditType of ' . Endpoint::Charge->value . ' is empty');

        $task->execute(new CreditMovementDTO(CreditMovement::Charge, 'user_8421', '1', new MovementOptions(' ')));
    }

    private function task(ScriptedApiClient $scriptedApiClient): MoveCreditsTask
    {
        return new MoveCreditsTask(new ApiTransport($scriptedApiClient, new ErrorMapper(), null), new CustomerAccountResource());
    }
}
