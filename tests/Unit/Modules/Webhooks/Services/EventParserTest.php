<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit\Modules\Webhooks\Services;

use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slen\Sdk\Exceptions\InvalidResponseException;
use Slen\Sdk\Modules\Operations\Enums\ExecutionStatus;
use Slen\Sdk\Modules\Webhooks\Data\ExecutionFinishedEventDTO;
use Slen\Sdk\Modules\Webhooks\Data\UnknownEventDTO;
use Slen\Sdk\Modules\Webhooks\Services\EventParser;

final class EventParserTest extends TestCase
{
    private const array ENVELOPE = ['id' => 'evt_1', 'type' => 'flow.published', 'created_at' => '2026-10-17T12:00:00+00:00', 'data' => ['flow_id' => '8']];

    public function testATypedEventReadsExactlyItsData(): void
    {
        $data = ['execution_id' => '7', 'flow_id' => '8', 'operation' => 'support.reply', 'status' => 'completed', 'error_code' => null];

        $event = (new EventParser())->parse(self::json([...self::ENVELOPE, 'type' => 'execution.finished', 'data' => $data]));

        self::assertInstanceOf(ExecutionFinishedEventDTO::class, $event);
        self::assertSame('7', $event->executionId);
        self::assertSame('support.reply', $event->operationCode);
        self::assertSame(ExecutionStatus::Completed, $event->status);
        self::assertSame($data, $event->data);
    }

    public function testAnEventOfAnotherTypeIsKnownByItsEnvelopeAlone(): void
    {
        $event = (new EventParser())->parse(self::json([...self::ENVELOPE, 'type' => 'something.new']));

        self::assertInstanceOf(UnknownEventDTO::class, $event);
        self::assertSame('something.new', $event->type);
        self::assertSame(['flow_id' => '8'], $event->data);
        self::assertSame('2026-10-17T12:00:00+00:00', $event->createdAt->format(DATE_ATOM));
    }

    #[DataProvider('notAnEventProvider')]
    public function testABodyThatIsNotAnEventIsRefusedAsOne(string $payload): void
    {
        try {
            (new EventParser())->parse($payload);
            self::fail('the body was read as an event');
        } catch (InvalidResponseException $invalidResponseException) {
            self::assertSame('webhook_payload_invalid', $invalidResponseException->errorCode);
        }
    }

    /**
     * @return Iterator<string, array{string}>
     */
    public static function notAnEventProvider(): Iterator
    {
        yield 'not JSON' => ['{"id":'];
        yield 'a list' => ['[1,2]'];
        yield 'no id' => [self::json(array_diff_key(self::ENVELOPE, ['id' => true]))];
        yield 'no type' => [self::json(array_diff_key(self::ENVELOPE, ['type' => true]))];
        yield 'no time' => [self::json(array_diff_key(self::ENVELOPE, ['created_at' => true]))];
        yield 'no data' => [self::json(array_diff_key(self::ENVELOPE, ['data' => true]))];
        yield 'a typed event without its fields' => [self::json([...self::ENVELOPE, 'type' => 'execution.finished'])];
    }

    /**
     * @param array<string, mixed> $event
     */
    private static function json(array $event): string
    {
        return json_encode($event, JSON_THROW_ON_ERROR);
    }
}
