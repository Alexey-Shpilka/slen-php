<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit\Modules\Webhooks;

use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slen\Sdk\Exceptions\InvalidResponseException;
use Slen\Sdk\Modules\Webhooks\Data\ApprovalDecidedEvent;
use Slen\Sdk\Modules\Webhooks\Data\ApprovalRequestedEvent;
use Slen\Sdk\Modules\Webhooks\Data\CustomerBalanceLowEvent;
use Slen\Sdk\Modules\Webhooks\Data\ExecutionFinishedEvent;
use Slen\Sdk\Modules\Webhooks\Data\WebhookEvent;
use Slen\Sdk\Modules\Webhooks\Enums\WebhookEventType;
use Slen\Sdk\Modules\Webhooks\Services\EventParser;
use Symfony\Component\Yaml\Yaml;

/**
 * The events against the `webhooks:` section of the API's OpenAPI spec (the file `SLEN_OPENAPI_SPEC` names;
 * skipped without it): every event is a case of the enum, and each
 * typed event reads the data its schema describes — all of it, and nothing the schema does not have.
 */
final class WebhookEventsContractTest extends TestCase
{
    private const string SPEC_VARIABLE = 'SLEN_OPENAPI_SPEC';

    private const array TYPED = [
        'execution.finished' => ExecutionFinishedEvent::class,
        'customer.balance_low' => CustomerBalanceLowEvent::class,
        'approval.requested' => ApprovalRequestedEvent::class,
        'approval.decided' => ApprovalDecidedEvent::class,
    ];

    /** @var array<string, mixed>|null */
    private static ?array $spec = null;

    public function testEveryEventOfTheSpecIsACaseOfTheEnumAndNoOtherIs(): void
    {
        $cases = array_map(static function (WebhookEventType $webhookEventType): string {
            return $webhookEventType->value;
        }, WebhookEventType::cases());
        $webhooks = array_keys(self::section(self::spec(), 'webhooks'));
        $enum = self::section(self::schema('WebhookEventType'), 'enum');

        sort($cases);
        sort($webhooks);
        sort($enum);

        self::assertSame($webhooks, $cases);
        self::assertSame($enum, $cases);
    }

    /**
     * @param class-string<WebhookEvent> $class
     */
    #[DataProvider('typedProvider')]
    public function testATypedEventReadsAllTheDataItsSchemaDescribesAndNothingElse(string $type, string $class): void
    {
        $schema = self::dataSchema($type);
        $data = [];

        foreach (self::section($schema, 'properties') as $name => $property) {
            $data[$name] = self::sample(self::resolve(self::array($property)));
        }

        self::assertInstanceOf($class, self::parse($type, $data), 'the data of the schema, and only it, is an event of its type');

        foreach (self::section($schema, 'required') as $name) {
            self::assertIsString($name);

            $without = $data;
            unset($without[$name]);

            if (self::nullable(self::resolve(self::array(self::section($schema, 'properties')[$name])))) {
                continue;
            }

            try {
                self::parse($type, $without);
                self::fail($type . ' was read without ' . $name);
            } catch (InvalidResponseException $invalidResponseException) {
                self::assertSame('webhook_payload_invalid', $invalidResponseException->errorCode);
            }
        }
    }

    /**
     * @return Iterator<string, array{string, class-string<WebhookEvent>}>
     */
    public static function typedProvider(): Iterator
    {
        foreach (self::TYPED as $type => $class) {
            yield $type => [$type, $class];
        }
    }

    /**
     * @param array<mixed> $data
     */
    private static function parse(string $type, array $data): WebhookEvent
    {
        $envelope = ['id' => '1', 'type' => $type, 'created_at' => '2026-10-17T12:00:00+00:00', 'data' => $data];

        return (new EventParser())->parse(json_encode($envelope, JSON_THROW_ON_ERROR));
    }

    /**
     * The schema of `data` in the request body of the event.
     *
     * @return array<mixed>
     */
    private static function dataSchema(string $type): array
    {
        $body = self::spec();

        foreach (['webhooks', $type, 'post', 'requestBody', 'content', 'application/json'] as $key) {
            $body = self::section($body, $key);
        }

        foreach (self::section(self::section($body, 'schema'), 'allOf') as $part) {
            $data = self::array(self::array($part)['properties'] ?? [])['data'] ?? null;

            if (\is_array($data)) {
                return self::resolve($data);
            }
        }

        self::fail('the event ' . $type . ' has no schema for its data');
    }

    /**
     * A value of the type the property describes: an id, a time, credits, the first value of an enum.
     *
     * @param array<mixed> $property
     */
    private static function sample(array $property): mixed
    {
        if (isset($property['oneOf'])) {
            return self::sample(self::resolve(self::array(self::section($property, 'oneOf')[0])));
        }

        if (isset($property['enum'])) {
            return self::section($property, 'enum')[0];
        }

        $types = (array) ($property['type'] ?? 'string');

        return match (true) {
            ($property['format'] ?? null) === 'date-time' => '2026-10-17T12:00:00+00:00',
            isset($property['pattern']) => '12.5000',
            \in_array('integer', $types, true) => 7,
            \in_array('object', $types, true) => [],
            default => 'text',
        };
    }

    /**
     * @param array<mixed> $property
     */
    private static function nullable(array $property): bool
    {
        $types = (array) ($property['type'] ?? []);
        $oneOf = array_map(static function (mixed $part): array {
            return self::resolve(self::array($part));
        }, (array) ($property['oneOf'] ?? []));

        return \in_array('null', $types, true) || \in_array(['type' => 'null'], $oneOf, true);
    }

    /**
     * @param array<mixed> $schema
     *
     * @return array<mixed>
     */
    private static function resolve(array $schema): array
    {
        $reference = $schema['$ref'] ?? null;

        return \is_string($reference) ? self::resolve(self::schema(substr($reference, \strlen('#/components/schemas/')))) : $schema;
    }

    /**
     * @return array<mixed>
     */
    private static function schema(string $name): array
    {
        return self::section(self::section(self::section(self::spec(), 'components'), 'schemas'), $name);
    }

    /**
     * @param array<mixed> $node
     *
     * @return array<mixed>
     */
    private static function section(array $node, string $key): array
    {
        self::assertArrayHasKey($key, $node);

        return self::array($node[$key]);
    }

    /**
     * @return array<mixed>
     */
    private static function array(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }

    /**
     * @return array<mixed>
     */
    private static function spec(): array
    {
        if (self::$spec !== null) {
            return self::$spec;
        }

        $named = getenv(self::SPEC_VARIABLE);
        $path = \is_string($named) === true ? $named : '';

        if ($path === '') {
            self::markTestSkipped('No OpenAPI spec to hold the events against: set ' . self::SPEC_VARIABLE . '.');
        }

        self::assertFileExists($path, self::SPEC_VARIABLE . ' names a file that is not there.');

        /** @var array<string, mixed> $spec */
        $spec = self::array(Yaml::parseFile($path));

        return self::$spec = $spec;
    }
}
