<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit;

use Closure;
use DateTimeInterface;
use Iterator;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use Slen\Sdk\Data\Health;
use Slen\Sdk\Data\Page;
use Slen\Sdk\Exceptions\BudgetExceededException;
use Slen\Sdk\Exceptions\InvalidRequestException;
use Slen\Sdk\Exceptions\SlenException;
use Slen\Sdk\Modules\Customers\Data\CreditEntry;
use Slen\Sdk\Modules\Customers\Data\CreditPriceList;
use Slen\Sdk\Modules\Customers\Data\Customer;
use Slen\Sdk\Modules\Customers\Data\CustomerAccount;
use Slen\Sdk\Modules\Customers\Enums\CreditEntryType;
use Slen\Sdk\Modules\Customers\Enums\CustomerStatus;
use Slen\Sdk\Modules\Customers\Options\AccountOptions;
use Slen\Sdk\Modules\Customers\Options\BlockOptions;
use Slen\Sdk\Modules\Customers\Options\CustomerListOptions;
use Slen\Sdk\Modules\Customers\Options\EntryListOptions;
use Slen\Sdk\Modules\Customers\Options\MovementOptions;
use Slen\Sdk\Modules\Customers\Options\PriceListOptions;
use Slen\Sdk\Modules\Executions\Data\ExecutionStatusView;
use Slen\Sdk\Modules\Executions\Data\ExecutionTrace;
use Slen\Sdk\Modules\Executions\Options\WaitOptions;
use Slen\Sdk\Modules\Operations\Data\ExecutionResult;
use Slen\Sdk\Modules\Operations\Options\ExecuteOptions;
use Slen\Sdk\Modules\Webhooks\Options\VerifyOptions;
use Slen\Sdk\RequestOptions;
use Slen\Sdk\Shared\Http\Data\ApiResponseDTO;
use Slen\Sdk\Shared\Http\Mappers\ErrorMapper;
use Slen\Sdk\SlenConfig;
use Slen\Sdk\SlenSdk;
use Slen\Sdk\Testing\FakeHttpClient;
use Slen\Sdk\Tests\Paths;
use Symfony\Component\Yaml\Yaml;
use UnitEnum;

/**
 * The SDK held against the wire contract: the API's OpenAPI spec, and the files of `contract/`.
 *
 * A call is an operation of the spec, sends what it describes (body keys, required keys, query names) and the
 * `Idempotency-Key` where and only where it is declared; an answer the spec allows is read into the DTO the call
 * returns, the fullest one filling every field and the leanest one read without a failure; a refusal is a body the
 * mapper reads; the error table and `ErrorCode` agree both ways. Without a spec (the file `SLEN_OPENAPI_SPEC`
 * names) those checks are skipped; the ones against `contract/calls.json` always run.
 */
final class ContractTest extends TestCase
{
    private const string SPEC_VARIABLE = 'SLEN_OPENAPI_SPEC';

    private const string ERROR_TABLE = Paths::CONTRACT . '/error-codes.json';

    private const string UNMAPPED_CODES = Paths::CONTRACT . '/unmapped-error-codes.json';

    private const string CALL_TABLE = Paths::CONTRACT . '/calls.json';

    private const string SOURCE = __DIR__ . '/../../src';

    private const string SDK = 'php';

    private const string KEY = 'sk_live_contract';

    private const string IDEMPOTENCY_HEADER = 'Idempotency-Key';

    private const string REFERENCE = '#/components/';

    private const string JSON_MEDIA_TYPE = 'application/json';

    private const string ID = '74438200973594624';

    private const array METHODS = ['get', 'post', 'put', 'patch', 'delete'];

    /**
     * Public calls of the SDK that make no request of the API, each with the reason: the TypeScript suite has
     * the same list (with `flush`, which PHP has no use for).
     */
    private const array NOT_CALLS = [
        'webhooks.verify' => 'checks the Slen-Signature of a webhook where it arrives; no request is made',
        'webhooks.constructEvent' => 'checks a webhook and reads it into its event where it arrives; no request is made',
    ];

    /**
     * Fields a DTO leaves null when it is read from the fullest answer the spec allows — on purpose, with the
     * reason. Held both ways: a null not listed here is a field read from nothing, and a listed one that is filled
     * is a stale line.
     */
    private const array KNOWN_NULLS = [];

    /**
     * A refusal in the two bodies the API answers one with — the envelope (`Error`) and a field validation of the
     * framework (`ValidationError`) — with a value in every field the mapper reads, and those fields as paths.
     */
    private const array REFUSALS = [
        'Error' => [
            'body' => [
                'error' => [
                    'code' => 'budget_exceeded',
                    'message' => 'The budget of the project is spent.',
                    'details' => ['budget_id' => '74438200973594000'],
                    'request_id' => 'req_contract',
                ],
            ],
            'reads' => ['error', 'error.code', 'error.message', 'error.details', 'error.request_id'],
        ],
        'ValidationError' => [
            'body' => [
                'message' => 'The credits field is required.',
                'errors' => ['credits' => ['The credits field is required.']],
            ],
            'reads' => ['message', 'errors'],
        ],
    ];

    private const string DATE_TIME = '2026-10-17T10:00:00+00:00';

    /** Strings a `pattern` of the spec is tried with, in order, when the schema gives no example. */
    private const array PATTERNED = ['74438200973594624', '12.5000', '0.00412500', 'support.reply', 'value'];

    /** @var array<array-key, mixed>|null */
    private static ?array $spec = null;

    public function testEveryCallIsAnOperationOfTheSpec(): void
    {
        $missing = $this->missingOperations($this->specOperations());

        self::assertSame([], $missing, implode("\n", $missing));

        foreach (self::calls() as $name => $row) {
            [$method, $template] = explode(' ', $row['operation'], 2);
            $request = self::requestOf($row['call']);

            self::assertSame($method, $request['method'], $name);
            self::assertMatchesRegularExpression(self::pathPattern($template), $request['path'], $name);
        }
    }

    /**
     * The check above is red for a spec that lost a call of the SDK — `chargeCustomer`, say.
     */
    public function testASpecWithoutTheChargeOfACustomerIsRed(): void
    {
        $operations = $this->specOperations();

        self::assertArrayHasKey('POST /v1/customers/{}/charge', $operations);

        unset($operations['POST /v1/customers/{}/charge']);

        self::assertSame(
            ['customers.charge: POST /v1/customers/{externalUserId}/charge is not an operation of the spec'],
            $this->missingOperations($operations),
        );
    }

    /**
     * What a call sends is what its operation describes: every key of the body is a property of the request
     * schema, every required property is sent by the bare form of the call (nothing optional passed), an operation
     * without a request body is sent none, and every name in the query is a query parameter of the operation.
     */
    public function testACallSendsWhatItsOperationDescribes(): void
    {
        $operations = $this->operationsOfTheSpec();
        $problems = [];
        $bodies = 0;

        foreach (self::calls() as $name => $row) {
            $declared = $operations[self::wire($row['operation'])] ?? null;

            if ($declared === null) {
                continue;
            }

            $request = self::requestOf($row['call']);
            $sent = array_map(strval(...), array_keys($request['body']));
            $asked = array_diff($request['query'], $this->queryParameters($declared));
            $schema = $this->jsonSchemaOf($declared['requestBody'] ?? null, 'requestBodies');

            if ($asked !== []) {
                $problems[] = \sprintf('%s puts %s in the query, which %s does not declare', $name, implode(', ', $asked), $row['operation']);
            }

            if ($schema === null) {
                if ($request['json'] !== null) {
                    $problems[] = \sprintf('%s sends a body, and %s takes none', $name, $row['operation']);
                }

                continue;
            }

            $wire = $this->objectOf($schema);
            $least = $row['bare'] === null ? $sent : array_map(strval(...), array_keys(self::requestOf($row['bare'])['body']));
            $unknown = array_diff($sent, array_keys($wire['properties']));
            $missing = array_diff($wire['required'], $least);
            ++$bodies;

            if ($unknown !== []) {
                $problems[] = \sprintf('%s sends %s, which the request of %s does not have', $name, implode(', ', $unknown), $row['operation']);
            }

            if ($missing !== []) {
                $problems[] = \sprintf('%s does not send %s unless asked to, and %s requires it', $name, implode(', ', $missing), $row['operation']);
            }

            if ($row['bare'] === null && array_diff($sent, $wire['required']) !== []) {
                $problems[] = \sprintf('%s sends keys the request does not require and has no bare form to show which it always sends', $name);
            }
        }

        self::assertGreaterThan(5, $bodies, 'Hardly any call has a request schema: the spec is not read the way this test expects.');
        self::assertSame([], $problems, implode("\n", $problems));
    }

    /**
     * A run and a movement of credits are repeated by the transport under the same key, and only the key makes
     * that safe; a key sent where the spec declares none promises a guard the server never made.
     */
    public function testACallSendsAnIdempotencyKeyWhereAndOnlyWhereTheSpecDeclaresOne(): void
    {
        $operations = $this->specOperations();
        $bare = [];
        $unasked = [];
        $keyed = 0;

        foreach (self::calls() as $name => $row) {
            $declared = $operations[self::wire($row['operation'])] ?? false;
            $sent = self::header(self::requestOf($row['call'])['headers'], self::IDEMPOTENCY_HEADER) !== '';
            $keyed += $declared ? 1 : 0;

            if ($declared && !$sent) {
                $bare[] = $name;
            }

            if (!$declared && $sent) {
                $unasked[] = $name;
            }
        }

        self::assertSame(5, $keyed, 'The two runs and the three movements of credits are the calls with a key.');
        self::assertSame([], $bare, 'The spec declares the Idempotency-Key on these calls and the SDK sent none: ' . implode(', ', $bare));
        self::assertSame([], $unasked, 'The SDK sent an Idempotency-Key where the operation declares none: ' . implode(', ', $unasked));
    }

    // The check above proves nothing if this test stops recognising the header in the spec.
    public function testTheSpecStillMarksTheMoneyMovingCalls(): void
    {
        $operations = $this->specOperations();

        self::assertTrue($operations['POST /v1/customers/{}/charge'] ?? false);
        self::assertTrue($operations['POST /v1/operations/execute'] ?? false);
        self::assertFalse($operations['PUT /v1/customers/{}'] ?? true);
    }

    /**
     * Every public call of the SDK is a row of this test — a line of `contract/calls.json` — or one of NOT_CALLS.
     */
    public function testEveryPublicCallOfTheSdkIsALineOfTheCallTableOrMakesNoRequest(): void
    {
        $rows = array_keys(iterator_to_array(self::calls()));
        $calls = array_keys(self::publicCalls());
        $expected = [...$rows, ...array_keys(self::NOT_CALLS)];

        sort($calls);
        sort($expected);

        self::assertSame($expected, $calls);
        self::assertSame([], array_intersect($rows, array_keys(self::NOT_CALLS)));
    }

    public function testACallThatIsNotOneMakesNoRequestAndHasNoLine(): void
    {
        $fakeHttpClient = new FakeHttpClient();
        $slenSdk = self::sdk($fakeHttpClient);

        self::assertFalse($slenSdk->webhooks()->verify('{}', null, new VerifyOptions('whsec_contract')));

        try {
            $slenSdk->webhooks()->constructEvent('{}', 't=1,v1=00', new VerifyOptions('whsec_contract'));
            self::fail('a webhook without a valid signature was read');
        } catch (SlenException) {
        }

        self::assertSame([], $fakeHttpClient->requests());

        foreach (self::NOT_CALLS as $name => $reason) {
            self::assertArrayNotHasKey($name, self::callTable(), $name . ' makes no request and has a line in contract/calls.json');
            self::assertNotSame('', trim($reason), $name);
        }
    }

    public function testEveryLineOfTheCallTableIsAnOperationOfBothSdks(): void
    {
        $problems = [];
        $table = self::callTable();

        self::assertCount(20, $table);

        foreach ($table as $name => $line) {
            if (preg_match('#^(?:GET|POST|PUT|PATCH|DELETE) /\S*$#', $line['operation']) !== 1) {
                $problems[] = \sprintf('%s: the operation is not `METHOD /path`: %s', $name, $line['operation']);
            }

            if ($line['sdks'] !== ['php', 'typescript']) {
                $problems[] = \sprintf('%s: `sdks` is not both SDKs: %s', $name, json_encode($line['sdks']));
            }
        }

        self::assertSame([], $problems, implode("\n", $problems));
    }

    /**
     * The table is held against the code with no spec: each call goes where its line says.
     */
    public function testEveryCallGoesToTheMethodAndThePathItsLineNames(): void
    {
        $problems = [];

        foreach (self::calls() as $name => $row) {
            [$method, $template] = explode(' ', $row['operation'], 2);
            $request = self::requestOf($row['call']);

            if ($request['method'] !== $method || preg_match(self::pathPattern($template), $request['path']) !== 1) {
                $problems[] = \sprintf('%s called %s %s, and its line says %s', $name, $request['method'], $request['path'], $row['operation']);
            }
        }

        self::assertSame([], $problems, implode("\n", $problems));
    }

    /**
     * A call of every page reads the fullest answer of the spec with a cursor, then the next page with that cursor,
     * and stops at the leanest answer, which has none: one row a page, two requests.
     */
    public function testACallOfEveryPageReadsPageAfterPageOfTheSpec(): void
    {
        $operations = $this->operationsOfTheSpec();
        $problems = [];

        foreach (self::iterations() as $name => $call) {
            $declared = $operations[self::wire(self::callTable()[$name]['operation'] ?? '')] ?? [];
            [$status, $schema] = $this->answersOf($declared)[0] ?? [200, null];
            $alternative = $this->alternativesOf($schema)[0];
            $fakeHttpClient = (new FakeHttpClient())
                ->pushJson($status, $this->answer($alternative, true, ['meta.next_cursor' => 'cursor-2']))
                ->pushJson($status, $this->answer($alternative, false, ['meta.next_cursor' => null]));

            $rows = $call(self::sdk($fakeHttpClient));
            $requests = $fakeHttpClient->requests();
            parse_str($requests[0]->getUri()->getQuery(), $first);
            parse_str(($requests[1] ?? $requests[0])->getUri()->getQuery(), $second);

            if (\count($rows) !== 2 || \count($requests) !== 2 || isset($first['cursor']) || ($second['cursor'] ?? null) !== 'cursor-2') {
                $problems[] = \sprintf('%s read %d rows in %d requests, the second with %s', $name, \count($rows), \count($requests), json_encode($second));
            }
        }

        self::assertSame([], $problems, implode("\n", $problems));
    }

    /**
     * The calls of every page, read to the end; each is a row of the table too.
     *
     * @return array<string, Closure(SlenSdk): list<object>>
     */
    private static function iterations(): array
    {
        $iterations = [
            'customers.entriesAll' => static function (SlenSdk $s): array {
                return iterator_to_array($s->customers()->entriesAll('user 42'), false);
            },
            'customers.listAll' => static function (SlenSdk $s): array {
                return iterator_to_array($s->customers()->listAll(), false);
            },
        ];

        self::assertSame(array_keys($iterations), array_values(array_filter(array_keys(self::callTable()), static function (string $name): bool {
            return str_ends_with($name, 'All');
        })));

        return $iterations;
    }

    /**
     * A row names the DTO its call returns, and the method says the same: its return type, or the `@return` of its
     * doc block for a list. Needs no spec.
     */
    public function testARowNamesTheDtoItsCallReturns(): void
    {
        $calls = self::publicCalls();
        $problems = [];

        foreach (self::calls() as $name => $row) {
            $method = $calls[$name] ?? null;

            if ($method === null) {
                continue;
            }

            $returns = (string) $method->getReturnType();

            if (preg_match('/@return\s+(.+)/', (string) $method->getDocComment(), $documented) === 1) {
                $returns .= ' ' . $documented[1];
            }

            $named = $row['dto'] === null ? 'void' : self::short($row['dto']);

            if (preg_match('/\b' . preg_quote($named, '/') . '\b/', $returns) !== 1) {
                $problems[] = \sprintf('%s: the row says %s, the method returns %s', $name, $named, $returns);
            }
        }

        self::assertSame([], $problems, implode("\n", $problems));
    }

    /**
     * The fullest answer the spec allows — every property, never null where it may be something else — is read
     * into the DTO of the call with every field filled. A field left null is read from a key the spec does not
     * have; a key the spec does not have that the SDK requires fails the read. Every DTO of an answer is reached.
     */
    public function testTheFullestAnswerOfTheSpecFillsEveryFieldOfTheDto(): void
    {
        $operations = $this->operationsOfTheSpec();
        $problems = [];
        $nulls = [];
        $reached = [];

        foreach (self::calls() as $name => $row) {
            $declared = $operations[self::wire($row['operation'])] ?? null;

            if ($declared === null || $row['dto'] === null) {
                continue;
            }

            $read = $this->readFromTheSpec($name, $row, $declared, true, $problems);

            if ($read !== null) {
                $this->collect($read, $nulls, $reached);
            }
        }

        $nulls = array_values(array_unique($nulls));
        $known = array_keys(self::KNOWN_NULLS);
        sort($nulls);
        sort($known);

        self::assertSame([], $problems, implode("\n", $problems));
        self::assertSame($known, $nulls, 'These fields stay null on the fullest answer of the spec: read from a key it does not have?');
        self::assertSame([], array_values(array_diff(self::answerDtos(), array_keys($reached))), 'These DTOs are read from no answer of the spec.');
    }

    /**
     * The leanest answer — only what is required, null wherever the spec allows it — is read without a failure:
     * a field the SDK insists on is one the spec promises.
     */
    public function testTheLeanestAnswerOfTheSpecIsRead(): void
    {
        $operations = $this->operationsOfTheSpec();
        $problems = [];

        foreach (self::calls() as $name => $row) {
            $declared = $operations[self::wire($row['operation'])] ?? null;

            if ($declared !== null && $row['dto'] !== null) {
                $this->readFromTheSpec($name, $row, $declared, false, $problems);
            }
        }

        self::assertSame([], $problems, implode("\n", $problems));
    }

    public function testEveryCodeOfTheErrorTableIsACodeOfTheSpec(): void
    {
        $unknown = array_diff(self::tableCodes(), $this->specErrorCodes());

        self::assertSame([], array_values($unknown), 'contract/error-codes.json names codes that are not in `ErrorCode`: ' . implode(', ', $unknown));
    }

    public function testEveryCodeOfTheSpecIsInTheTableOrListedAsUnmapped(): void
    {
        $undecided = array_diff($this->specErrorCodes(), self::tableCodes(), array_keys(self::unmappedCodes()));

        self::assertSame([], array_values($undecided), \sprintf(
            "`ErrorCode` has codes the SDKs have not decided on: %s.\n"
            . 'Give each a type (contract/error-codes.json and both tables) or a line in contract/unmapped-error-codes.json.',
            implode(', ', $undecided),
        ));
    }

    public function testTheUnmappedListHoldsOnlyLiveCodesOutsideTheTable(): void
    {
        $unmapped = self::unmappedCodes();
        $listed = array_keys($unmapped);
        $stale = array_diff($listed, $this->specErrorCodes());
        $both = array_intersect($listed, self::tableCodes());

        self::assertSame([], array_values($stale), 'contract/unmapped-error-codes.json lists codes that left `ErrorCode`: ' . implode(', ', $stale));
        self::assertSame([], array_values($both), 'These codes are both in the table and listed as unmapped: ' . implode(', ', $both));

        foreach ($unmapped as $code => $reason) {
            self::assertNotSame('', trim($reason), $code . ' is listed as unmapped without a reason');
        }
    }

    /**
     * The bodies of REFUSALS are held against the mapper itself, with no spec: each field a body names is read.
     */
    public function testTheErrorMapperReadsEveryFieldOfTheRefusalsOfThisTest(): void
    {
        $envelope = self::REFUSALS['Error']['body'];
        $validation = self::REFUSALS['ValidationError']['body'];

        $refused = (new ErrorMapper())->map(new ApiResponseDTO(402, [], $envelope));

        self::assertInstanceOf(BudgetExceededException::class, $refused);
        self::assertSame(
            [$envelope['error']['code'], $envelope['error']['message'], $envelope['error']['details'], $envelope['error']['request_id']],
            [$refused->errorCode, $refused->getMessage(), $refused->details, $refused->requestId],
        );

        $invalid = (new ErrorMapper())->map(new ApiResponseDTO(422, [], $validation));

        self::assertInstanceOf(InvalidRequestException::class, $invalid);
        self::assertSame([null, $validation['message'], ['errors' => $validation['errors']]], [$invalid->errorCode, $invalid->getMessage(), $invalid->details]);

        foreach (self::REFUSALS as $name => ['body' => $body, 'reads' => $reads]) {
            foreach ($reads as $read) {
                self::assertNotNull(self::at($body, $read), \sprintf('REFUSALS: the %s body has nothing at %s.', $name, $read));
            }
        }
    }

    /**
     * The mapper reads the envelope and the framework's validation, nothing else: a refusal in a third shape, in
     * another media type or with no body would reach the caller with no code and a made-up message.
     */
    public function testARefusalOfEveryCallIsABodyTheErrorMapperReads(): void
    {
        $operations = $this->operationsOfTheSpec();
        $problems = [];
        $refusals = 0;

        foreach (self::calls() as $row) {
            $declared = $operations[self::wire($row['operation'])] ?? null;

            if ($declared === null) {
                continue;
            }

            foreach (self::map($declared['responses'] ?? null) as $status => $given) {
                if (str_starts_with((string) $status, '2')) {
                    continue;
                }

                ++$refusals;
                $content = self::map($this->resolved($given, 'responses')['content'] ?? null);
                $otherTypes = array_diff(array_map(strval(...), array_keys($content)), [self::JSON_MEDIA_TYPE]);
                $schema = self::map($content[self::JSON_MEDIA_TYPE] ?? null)['schema'] ?? null;

                if ($otherTypes !== []) {
                    $problems[] = \sprintf('%s answers %s as %s, which the mapper does not read', $row['operation'], $status, implode(', ', $otherTypes));
                }

                if ($schema === null) {
                    $problems[] = \sprintf('%s answers %s with no %s body the mapper could read', $row['operation'], $status, self::JSON_MEDIA_TYPE);

                    continue;
                }

                $foreign = array_diff($this->bodiesOf($schema), array_keys(self::REFUSALS));

                if ($foreign !== []) {
                    $problems[] = \sprintf('%s answers %s with %s, which the mapper does not read', $row['operation'], $status, implode(', ', $foreign));
                }
            }
        }

        self::assertGreaterThan(30, $refusals, 'Hardly any refusal of a call was found: the spec is not read the way this test expects.');
        self::assertSame([], array_values(array_unique($problems)), implode("\n", array_unique($problems)));
    }

    public function testTheErrorSchemasHaveEveryFieldTheErrorMapperReads(): void
    {
        $missing = [];

        foreach (self::REFUSALS as $name => ['reads' => $reads]) {
            foreach ($reads as $read) {
                $gap = $this->missingIn(['$ref' => self::REFERENCE . 'schemas/' . $name], $read);

                if ($gap !== null) {
                    $missing[] = $name . ': ' . $gap;
                }
            }
        }

        self::assertSame([], $missing, 'The error schemas of the spec no longer have fields the mapper reads: ' . implode(', ', $missing));

        $error = $this->objectOf(['$ref' => self::REFERENCE . 'schemas/Error'])['properties']['error'] ?? null;
        $code = $this->objectOf($error)['properties']['code'] ?? null;

        self::assertSame(['$ref' => self::REFERENCE . 'schemas/ErrorCode'], $code, 'Error.error.code is no longer the ErrorCode enum.');
    }

    /**
     * The SDK puts `/api` before every path; the spec says where its paths are served in `servers`.
     */
    public function testEveryPathIsServedUnderThePrefixTheSdkPutsBeforeIt(): void
    {
        $path = self::requestOf(static function (SlenSdk $slenSdk): mixed {
            return $slenSdk->health();
        }, 'https://host.example')['url'];
        $prefix = (string) parse_url(substr($path, 0, -\strlen('/health')), PHP_URL_PATH);
        $paths = array_map(
            static function (mixed $server): string {
                return rtrim((string) parse_url(self::text(self::map($server)['url'] ?? null), PHP_URL_PATH), '/');
            },
            self::listOf($this->spec()['servers'] ?? null),
        );

        self::assertSame('/api', $prefix);
        self::assertNotSame([], $paths, 'The spec names no server.');
        self::assertSame([], array_values(array_diff($paths, [$prefix])), 'The spec serves its paths under ' . implode(', ', $paths));
    }

    public function testTheFirstServerIsTheHostTheSdkTalksToByDefault(): void
    {
        $first = self::map(self::listOf($this->spec()['servers'] ?? null)[0] ?? null);
        $url = self::requestOf(static function (SlenSdk $slenSdk): mixed {
            return $slenSdk->health();
        }, null)['url'];

        self::assertSame(rtrim(self::text($first['url'] ?? null), '/') . '/health', $url);
    }

    /**
     * Every request carries `Authorization: Bearer <key>`; an operation that asks for another credential would
     * be a 401 on every call with nothing in the SDK to show why.
     */
    public function testWhereverAnOperationAsksForACredentialItIsTheBearerTokenTheSdkSends(): void
    {
        $spec = $this->spec();
        $schemes = self::map(self::map($spec['components'] ?? null)['securitySchemes'] ?? null);
        $bearer = array_keys(array_filter(
            $schemes,
            static function (mixed $scheme): bool {
                return strtolower(self::text(self::map($scheme)['type'] ?? null)) === 'http'
                    && strtolower(self::text(self::map($scheme)['scheme'] ?? null)) === 'bearer';
            },
        ));
        $takesTheBearer = static function (array $requirements) use ($bearer): bool {
            foreach ($requirements as $requirement) {
                if (\is_array($requirement) && array_diff(array_keys($requirement), $bearer) === []) {
                    return true;
                }
            }

            return false;
        };
        $global = self::listOf($spec['security'] ?? null);
        $operations = $this->operationsOfTheSpec();
        $problems = [];
        $asking = 0;

        self::assertNotSame([], $bearer, 'The spec declares no bearer scheme.');
        self::assertTrue($takesTheBearer($global), 'The spec as a whole asks for something else than a bearer token.');

        foreach (self::calls() as $name => $row) {
            $declared = $operations[self::wire($row['operation'])] ?? [];
            $requirements = \array_key_exists('security', $declared) ? self::listOf($declared['security']) : $global;

            if ($requirements === []) {
                continue;
            }

            ++$asking;

            if (!$takesTheBearer($requirements)) {
                $problems[] = \sprintf('%s asks for another credential than the bearer token %s sends', $row['operation'], $name);
            }

            if (self::header(self::requestOf($row['call'])['headers'], 'Authorization') !== 'Bearer ' . self::KEY) {
                $problems[] = $name . ' sent no bearer token';
            }
        }

        self::assertGreaterThan(15, $asking);
        self::assertSame([], $problems, implode("\n", $problems));
    }

    /**
     * The rows: each line of `contract/calls.json` that names php, with the call (passing everything it can
     * send), its bare form where it sends more than is required, the DTO it returns and what an answer has to
     * say for the call to end at it (a wait reads until the run is over).
     *
     * @return Iterator<string, array{
     *     operation: string,
     *     call: Closure(SlenSdk): mixed,
     *     bare: (Closure(SlenSdk): mixed)|null,
     *     dto: class-string|null,
     *     pins: array<string, mixed>
     * }>
     */
    private static function calls(): Iterator
    {
        $lines = self::linesOfThisSdk();

        foreach (self::rows() as $name => $row) {
            yield $name => ['operation' => $lines[$name]['operation'] ?? '', ...$row];
        }
    }

    /**
     * @return Iterator<string, array{call: Closure(SlenSdk): mixed, bare: (Closure(SlenSdk): mixed)|null, dto: class-string|null, pins: array<string, mixed>}>
     */
    private static function rows(): Iterator
    {
        $input = ['messages' => [['role' => 'user', 'content' => 'Where is my order?']]];
        $everything = new ExecuteOptions(
            customer: 'user 42',
            creditType: 'default',
            context: ['ticket' => 42],
            idempotencyKey: 'ticket-42:reply',
            timeout: 20.0,
            retries: 0,
        );
        $run = static function (SlenSdk $s) use ($input, $everything): ExecutionResult {
            return $s->operations()->execute('support.reply', $input, $everything);
        };

        yield 'operations.execute' => [
            'call' => $run,
            'bare' => static function (SlenSdk $s) use ($input): ExecutionResult {
                return $s->operations()->execute('support.reply', $input);
            },
            'dto' => ExecutionResult::class,
            'pins' => [],
        ];
        yield 'operations.executeAsync' => [
            'call' => static function (SlenSdk $s) use ($input, $everything): ExecutionResult {
                return $s->operations()->executeAsync('support.reply', $input, $everything);
            },
            'bare' => static function (SlenSdk $s) use ($input): ExecutionResult {
                return $s->operations()->executeAsync('support.reply', $input);
            },
            'dto' => ExecutionResult::class,
            'pins' => [],
        ];
        yield 'executions.get' => self::row(
            static function (SlenSdk $s): ExecutionStatusView {
                return $s->executions()->get(self::ID, new RequestOptions(timeout: 5.0));
            },
            ExecutionStatusView::class,
        );
        yield 'executions.wait' => self::row(
            static function (SlenSdk $s): ExecutionStatusView {
                return $s->executions()->wait(self::ID, new WaitOptions(timeoutSeconds: 5));
            },
            ExecutionStatusView::class,
            ['data.status' => 'completed'],
        );
        yield 'executions.trace' => self::row(
            static function (SlenSdk $s): ExecutionTrace {
                return $s->executions()->trace(self::ID, new RequestOptions(timeout: 5.0));
            },
            ExecutionTrace::class,
        );
        yield 'customers.list' => [
            'call' => static function (SlenSdk $s): Page {
                return $s->customers()->list(
                    new CustomerListOptions(self::ID, 30, 'user', CustomerStatus::Active, 'default', '10', 5.0, 0),
                );
            },
            'bare' => static function (SlenSdk $s): Page {
                return $s->customers()->list();
            },
            'dto' => Page::class,
            'pins' => [],
        ];
        yield 'customers.listAll' => [
            'call' => static function (SlenSdk $s): Customer {
                return $s->customers()->listAll(
                    new CustomerListOptions(self::ID, 30, 'user', CustomerStatus::Active, 'default', '10', 5.0, 0),
                )->current();
            },
            'bare' => static function (SlenSdk $s): Customer {
                return $s->customers()->listAll()->current();
            },
            'dto' => Customer::class,
            'pins' => [],
        ];
        yield 'customers.get' => self::row(static function (SlenSdk $s): Customer {
            return $s->customers()->get('user 42');
        }, Customer::class);
        yield 'customers.create' => self::row(static function (SlenSdk $s): Customer {
            return $s->customers()->create('user 42');
        }, Customer::class);
        yield 'customers.updateAccount' => self::row(
            static function (SlenSdk $s): CustomerAccount {
                return $s->customers()->updateAccount('user 42', 'search', new AccountOptions(true, '5.0000'));
            },
            CustomerAccount::class,
        );
        yield 'customers.block' => [
            'call' => static function (SlenSdk $s): Customer {
                return $s->customers()->block('user 42', new BlockOptions('chargeback'));
            },
            'bare' => static function (SlenSdk $s): Customer {
                return $s->customers()->block('user 42');
            },
            'dto' => Customer::class,
            'pins' => [],
        ];
        yield 'customers.unblock' => self::row(static function (SlenSdk $s): Customer {
            return $s->customers()->unblock('user 42');
        }, Customer::class);
        yield 'customers.delete' => self::row(static function (SlenSdk $s): mixed {
            $s->customers()->delete('user 42');

            return null;
        }, null);

        yield 'customers.topUp' => [
            'call' => static function (SlenSdk $s): CustomerAccount {
                return $s->customers()
                    ->topUp('user 42', '5.0000', new MovementOptions('search', 'invoice 7781', 'invoice-7781:topup'));
            },
            'bare' => static function (SlenSdk $s): CustomerAccount {
                return $s->customers()->topUp('user 42', '5.0000');
            },
            'dto' => CustomerAccount::class,
            'pins' => [],
        ];
        yield 'customers.charge' => [
            'call' => static function (SlenSdk $s): CustomerAccount {
                return $s->customers()
                    ->charge('user 42', '5.0000', new MovementOptions('search', 'PDF export', 'export-991:charge'));
            },
            'bare' => static function (SlenSdk $s): CustomerAccount {
                return $s->customers()->charge('user 42', '5.0000');
            },
            'dto' => CustomerAccount::class,
            'pins' => [],
        ];
        yield 'customers.adjust' => [
            'call' => static function (SlenSdk $s): CustomerAccount {
                return $s->customers()
                    ->adjust('user 42', '-5.0000', new MovementOptions('search', 'goodwill', 'ticket-7:adjust'));
            },
            'bare' => static function (SlenSdk $s): CustomerAccount {
                return $s->customers()->adjust('user 42', '-5.0000');
            },
            'dto' => CustomerAccount::class,
            'pins' => [],
        ];

        yield 'customers.entries' => [
            'call' => static function (SlenSdk $s): Page {
                return $s->customers()
                    ->entries('user 42', new EntryListOptions(self::ID, 30, 'default', CreditEntryType::Charge));
            },
            'bare' => static function (SlenSdk $s): Page {
                return $s->customers()->entries('user 42');
            },
            'dto' => Page::class,
            'pins' => [],
        ];
        yield 'customers.entriesAll' => [
            'call' => static function (SlenSdk $s): CreditEntry {
                return $s->customers()
                    ->entriesAll('user 42', new EntryListOptions(self::ID, 30, 'default', CreditEntryType::Charge))
                    ->current();
            },
            'bare' => static function (SlenSdk $s): CreditEntry {
                return $s->customers()->entriesAll('user 42')->current();
            },
            'dto' => CreditEntry::class,
            'pins' => [],
        ];
        yield 'customers.prices' => [
            'call' => static function (SlenSdk $s): CreditPriceList {
                return $s->customers()->prices(new PriceListOptions('support.reply', 'default'));
            },
            'bare' => static function (SlenSdk $s): CreditPriceList {
                return $s->customers()->prices();
            },
            'dto' => CreditPriceList::class,
            'pins' => [],
        ];
        yield 'health' => self::row(static function (SlenSdk $s): Health {
            return $s->health();
        }, Health::class);
    }

    /**
     * @param Closure(SlenSdk): mixed $call
     * @param class-string|null       $dto
     * @param array<string, mixed>    $pins
     *
     * @return array{call: Closure(SlenSdk): mixed, bare: null, dto: class-string|null, pins: array<string, mixed>}
     */
    private static function row(Closure $call, ?string $dto, array $pins = []): array
    {
        return ['call' => $call, 'bare' => null, 'dto' => $dto, 'pins' => $pins];
    }

    /**
     * The calls of the SDK as `module.method`: each public method of the facade a method of SlenSdk hands out,
     * and each method of SlenSdk that answers a type of `Data` itself. The help slice classifies them the same way.
     *
     * @return array<string, ReflectionMethod>
     */
    private static function publicCalls(): array
    {
        $calls = [];

        foreach ((new ReflectionClass(SlenSdk::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $reflectionMethod) {
            $returns = $reflectionMethod->getReturnType();

            if ($reflectionMethod->isStatic() || !$returns instanceof ReflectionNamedType || $returns->isBuiltin()) {
                continue;
            }

            /** @var class-string $returned */
            $returned = $returns->getName();

            if (str_ends_with($returned, 'Facade')) {
                $calls = [...$calls, ...self::callsOfTheFacade($reflectionMethod->getName(), $returned)];
            } elseif (str_contains($returned, '\\Data\\')) {
                $calls[$reflectionMethod->getName()] = $reflectionMethod;
            }
        }

        return $calls;
    }

    /**
     * @param class-string $facade
     *
     * @return array<string, ReflectionMethod>
     */
    private static function callsOfTheFacade(string $module, string $facade): array
    {
        $calls = [];

        foreach ((new ReflectionClass($facade))->getMethods(ReflectionMethod::IS_PUBLIC) as $reflectionMethod) {
            if (!$reflectionMethod->isConstructor() && !$reflectionMethod->isStatic()) {
                $calls[$module . '.' . $reflectionMethod->getName()] = $reflectionMethod;
            }
        }

        return $calls;
    }

    /**
     * The DTO classes an answer of the API is read into.
     *
     * @return list<string>
     */
    private static function answerDtos(): array
    {
        $classes = [];

        foreach (['Modules/Operations/Data', 'Modules/Executions/Data', 'Modules/Customers/Data', 'Data'] as $directory) {
            $namespace = 'Slen\\Sdk\\' . str_replace('/', '\\', $directory) . '\\';

            $files = glob(self::SOURCE . '/' . $directory . '/*.php');

            foreach ($files === false ? [] : $files as $file) {
                /** @var class-string $name */
                $name = $namespace . basename($file, '.php');
                $class = new ReflectionClass($name);

                // The SDK's own carriers are not read from an answer.
                if (str_contains((string) $class->getDocComment(), '@internal') === false) {
                    $classes[] = $class->getName();
                }
            }
        }

        self::assertNotSame([], $classes);
        sort($classes);

        return $classes;
    }

    /**
     * @param array{call: Closure(SlenSdk): mixed, dto: class-string|null, pins: array<string, mixed>} $row
     * @param array<array-key, mixed>                                                               $declared
     * @param list<string>                                                                          $problems
     */
    private function readFromTheSpec(string $name, array $row, array $declared, bool $fullest, array &$problems): mixed
    {
        $answers = $this->answersOf($declared);
        $failures = [];

        if ($answers === []) {
            $problems[] = $name . ' has no 2xx JSON answer in the spec';

            return null;
        }

        foreach ($answers as [$status, $schema]) {
            foreach ($this->alternativesOf($schema) as $alternative) {
                $fakeHttpClient = (new FakeHttpClient())->pushJson($status, $this->answer($alternative, $fullest, $row['pins']));

                try {
                    $read = $row['call'](self::sdk($fakeHttpClient));
                } catch (SlenException $slenException) {
                    $failures[] = \sprintf('%s of %s: %s', $this->schemaName($alternative), $status, $slenException->getMessage());

                    continue;
                }

                $returned = \is_array($read) ? ($read[0] ?? null) : $read;

                if ($row['dto'] === null || !$returned instanceof $row['dto']) {
                    $problems[] = \sprintf('%s answered %s, not %s', $name, get_debug_type($returned), $row['dto'] ?? 'nothing');
                }

                if (\count($fakeHttpClient->requests()) !== 1) {
                    $problems[] = $name . ' made more than one request for one answer';
                }

                return $read;
            }
        }

        $problems[] = \sprintf('%s reads no %s answer of the spec: %s', $name, $fullest ? 'fullest' : 'leanest', implode('; ', $failures));

        return null;
    }

    /**
     * The fields left null in what a call read, as `ClassDTO.field`, and the DTO classes it reached.
     *
     * @param list<string>        $nulls
     * @param array<string, true> $reached
     */
    private function collect(mixed $value, array &$nulls, array &$reached): void
    {
        if (\is_array($value)) {
            foreach ($value as $item) {
                $this->collect($item, $nulls, $reached);
            }

            return;
        }

        if (!\is_object($value) || $value instanceof UnitEnum || $value instanceof DateTimeInterface) {
            return;
        }

        $reached[$value::class] = true;

        foreach ((new ReflectionClass($value))->getProperties(ReflectionProperty::IS_PUBLIC) as $reflectionProperty) {
            $field = $reflectionProperty->getValue($value);

            if ($field === null) {
                $nulls[] = self::short($value::class) . '.' . $reflectionProperty->getName();

                continue;
            }

            $this->collect($field, $nulls, $reached);
        }
    }

    /**
     * A value of the schema as the API could answer it. The fullest has every property and is null nowhere it
     * may be something else; the leanest has only the required properties and is null wherever it may be.
     * A list has one item either way, so that its items are read.
     */
    private function sample(mixed $given, bool $fullest, int $depth = 0): mixed
    {
        self::assertLessThan(16, $depth, 'The schema nests deeper than an answer of the API does.');

        $schema = $this->resolved($given, 'schemas');

        if (\array_key_exists('const', $schema)) {
            return $schema['const'];
        }

        $alternatives = self::listOf($schema['oneOf'] ?? $schema['anyOf'] ?? null);

        if ($alternatives !== []) {
            return $this->sampleOneOf($alternatives, $fullest, $depth);
        }

        if (!$fullest && self::mayBeNull($schema)) {
            return null;
        }

        return $this->sampleOfType($schema, $fullest, $depth);
    }

    /**
     * The first alternative that is not null; null itself for the leanest answer when one alternative is.
     *
     * @param list<mixed> $alternatives
     */
    private function sampleOneOf(array $alternatives, bool $fullest, int $depth): mixed
    {
        $values = array_values(array_filter($alternatives, function (mixed $alternative): bool {
            return !$this->isNullSchema($alternative);
        }));

        if (!$fullest && \count($values) < \count($alternatives)) {
            return null;
        }

        return $this->sample($values[0] ?? null, $fullest, $depth + 1);
    }

    /**
     * @param array<array-key, mixed> $schema
     */
    private function sampleOfType(array $schema, bool $fullest, int $depth): mixed
    {
        return match (self::typeOf($schema)) {
            'object' => $this->sampleObject($schema, $fullest, $depth),
            'array' => [$this->sample($schema['items'] ?? [], $fullest, $depth + 1)],
            'integer' => self::listOf($schema['enum'] ?? null)[0] ?? $schema['minimum'] ?? 1,
            'number' => 1.5,
            'boolean' => true,
            'string' => $this->sampleString($schema),
            default => 'value',
        };
    }

    /**
     * @param array<array-key, mixed> $schema
     */
    private static function mayBeNull(array $schema): bool
    {
        $types = \is_array($schema['type'] ?? null) ? $schema['type'] : [$schema['type'] ?? null];

        return \in_array('null', $types, true) || ($schema['nullable'] ?? false) === true;
    }

    /**
     * The type of a value the schema describes besides null; `object` for one that only lists properties.
     *
     * @param array<array-key, mixed> $schema
     */
    private static function typeOf(array $schema): ?string
    {
        $types = \is_array($schema['type'] ?? null) ? $schema['type'] : [$schema['type'] ?? null];
        $type = array_values(array_filter($types, static function (mixed $one): bool {
            return \is_string($one) && $one !== 'null';
        }))[0] ?? null;

        if ($type === null && ($schema['properties'] ?? $schema['allOf'] ?? null) !== null) {
            return 'object';
        }

        return \is_string($type) ? $type : null;
    }

    /**
     * The sample of an answer with the values the row pins on top.
     *
     * @param array<string, mixed> $pins
     *
     * @return array<array-key, mixed>
     */
    private function answer(mixed $schema, bool $fullest, array $pins): array
    {
        $body = self::map($this->sample($schema, $fullest));

        foreach ($pins as $path => $value) {
            $body = self::with($body, $path, $value);
        }

        return $body;
    }

    /**
     * The JSON schema of a request body or an answer, after its `$ref`; null when it has none.
     */
    private function jsonSchemaOf(mixed $given, string $section): mixed
    {
        if ($given === null) {
            return null;
        }

        $content = self::map($this->resolved($given, $section)['content'] ?? null);

        return self::map($content[self::JSON_MEDIA_TYPE] ?? null)['schema'] ?? null;
    }

    /**
     * @param array<array-key, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private function sampleObject(array $schema, bool $fullest, int $depth): array
    {
        $object = $this->objectOf($schema);
        $value = [];

        foreach ($object['properties'] as $property => $propertySchema) {
            if ($fullest || \in_array((string) $property, $object['required'], true)) {
                $value[(string) $property] = $this->sample($propertySchema, $fullest, $depth + 1);
            }
        }

        if ($object['properties'] === [] && $fullest) {
            $additional = $schema['additionalProperties'] ?? true;
            $value['key'] = \is_array($additional) ? $this->sample($additional, $fullest, $depth + 1) : 'value';
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $schema
     */
    private function sampleString(array $schema): string
    {
        foreach ([self::listOf($schema['enum'] ?? null), self::listOf($schema['examples'] ?? null), [$schema['example'] ?? null]] as $candidates) {
            foreach ($candidates as $candidate) {
                if (\is_string($candidate)) {
                    return $candidate;
                }
            }
        }

        // A time the spec gives no format to says so in words (`FlowExecutionEvent.occurred_at`).
        if (($schema['format'] ?? null) === 'date-time' || str_contains(self::text($schema['description'] ?? null), 'ISO-8601')) {
            return self::DATE_TIME;
        }

        $pattern = $schema['pattern'] ?? null;

        if (\is_string($pattern)) {
            foreach (self::PATTERNED as $candidate) {
                if (preg_match('~' . str_replace('~', '\~', $pattern) . '~u', $candidate) === 1) {
                    return $candidate;
                }
            }

            self::fail('No sample string matches the pattern ' . $pattern . ': add one to PATTERNED.');
        }

        return 'value';
    }

    private function isNullSchema(mixed $given): bool
    {
        $schema = $this->resolved($given, 'schemas');

        $type = $schema['type'] ?? null;

        return $type === 'null' || $type === ['null'];
    }

    /**
     * What the answer may be: each alternative of a `oneOf` (`GET /v1/customers` by its `view`), or itself.
     *
     * @return list<mixed>
     */
    private function alternativesOf(mixed $given): array
    {
        $alternatives = self::listOf($this->resolved($given, 'schemas')['oneOf'] ?? null);

        return $alternatives === [] ? [$given] : $alternatives;
    }

    /**
     * @param array<string, mixed> $operations as `wire()` spells them
     *
     * @return list<string>
     */
    private function missingOperations(array $operations): array
    {
        $missing = [];

        foreach (self::calls() as $name => $row) {
            if (!\array_key_exists(self::wire($row['operation']), $operations)) {
                $missing[] = \sprintf('%s: %s is not an operation of the spec', $name, $row['operation']);
            }
        }

        return $missing;
    }

    /**
     * The one request a call makes, on an SDK whose wire answers what cannot be read: the request is what counts.
     *
     * @param Closure(SlenSdk): mixed $call
     *
     * @return array{
     *     method: string,
     *     url: string,
     *     path: string,
     *     query: list<string>,
     *     headers: array<string, string>,
     *     body: array<array-key, mixed>,
     *     json: string|null
     * }
     */
    private static function requestOf(Closure $call, ?string $baseUrl = 'https://api.slen.ai'): array
    {
        $fakeHttpClient = (new FakeHttpClient())->pushJson(200, []);

        try {
            $call(self::sdk($fakeHttpClient, $baseUrl));
        } catch (SlenException) {
        }

        self::assertCount(1, $fakeHttpClient->requests(), 'A call of the table is one request.');

        $request = $fakeHttpClient->lastRequest();
        $uri = $request->getUri();
        $raw = (string) $request->getBody();
        parse_str($uri->getQuery(), $query);
        $headers = [];

        foreach (array_keys($request->getHeaders()) as $header) {
            $headers[(string) $header] = $request->getHeaderLine((string) $header);
        }

        return [
            'method' => $request->getMethod(),
            'url' => (string) $uri->withQuery(''),
            'path' => (string) preg_replace('#^/api#', '', $uri->getPath()),
            'query' => array_map(strval(...), array_keys($query)),
            'headers' => $headers,
            'body' => $raw === '' ? [] : self::map(json_decode($raw, true, 512, JSON_THROW_ON_ERROR)),
            'json' => $raw === '' ? null : $raw,
        ];
    }

    private static function sdk(FakeHttpClient $fakeHttpClient, ?string $baseUrl = 'https://api.slen.ai'): SlenSdk
    {
        $psr17Factory = new Psr17Factory();
        $slenConfig = $baseUrl === null ? new SlenConfig(self::KEY, retries: 0) : new SlenConfig(self::KEY, $baseUrl, retries: 0);

        return SlenSdk::create($slenConfig->withHttpClient($fakeHttpClient, $psr17Factory, $psr17Factory));
    }

    /**
     * @param array<string, string> $headers
     */
    private static function header(array $headers, string $name): string
    {
        foreach ($headers as $sent => $value) {
            if (strcasecmp($sent, $name) === 0) {
                return trim($value);
            }
        }

        return '';
    }

    /**
     * @param array<array-key, mixed> $body
     */
    private static function at(array $body, string $path): mixed
    {
        $value = $body;

        foreach (explode('.', $path) as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return null;
            }

            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $answer
     *
     * @return array<array-key, mixed>
     */
    private static function with(array $answer, string $path, mixed $value): array
    {
        $keys = explode('.', $path);
        $key = array_shift($keys);

        $answer[$key] = $keys === [] ? $value : self::with(self::map($answer[$key] ?? null), implode('.', $keys), $value);

        return $answer;
    }

    /**
     * @return list<string>
     */
    private function bodiesOf(mixed $schema): array
    {
        $alternatives = self::listOf(self::map($schema)['oneOf'] ?? null);

        if ($alternatives !== []) {
            return array_merge(...array_map($this->bodiesOf(...), $alternatives));
        }

        $reference = self::map($schema)['$ref'] ?? null;

        return [\is_string($reference) ? basename($reference) : '(a schema written in place)'];
    }

    private static function pathPattern(string $template): string
    {
        return '#^' . preg_replace('#\\\\\{[^/]+?\\\\\}#', '[^/]+', preg_quote($template, '#')) . '$#';
    }

    /**
     * An operation as it is on the wire: `METHOD /path` with every path parameter as `{}`.
     */
    private static function wire(string $operation): string
    {
        return (string) preg_replace('#\{[^/}]*\}#', '{}', $operation);
    }

    /**
     * Every operation of the spec, with whether it declares the `Idempotency-Key` header.
     *
     * @return array<string, bool>
     */
    private function specOperations(): array
    {
        return array_map(
            function (array $operation): bool {
                return $this->declaresIdempotencyKey(self::listOf($operation['parameters'] ?? null));
            },
            $this->operationsOfTheSpec(),
        );
    }

    /**
     * Every operation of the spec as it is written, keyed as `wire()` spells it; `parameters` holds its own and
     * the ones of its path item.
     *
     * @return array<string, array<array-key, mixed>>
     */
    private function operationsOfTheSpec(): array
    {
        $operations = [];

        foreach (self::map($this->spec()['paths'] ?? null) as $path => $item) {
            $item = self::map($item);

            foreach (self::METHODS as $method) {
                if (!\is_array($item[$method] ?? null)) {
                    continue;
                }

                $operation = $item[$method];
                $operation['parameters'] = [...self::listOf($item['parameters'] ?? null), ...self::listOf($operation['parameters'] ?? null)];
                $operations[self::wire(strtoupper($method) . ' ' . $path)] = $operation;
            }
        }

        self::assertNotSame([], $operations, 'The spec has no operations.');

        return $operations;
    }

    /**
     * @param list<mixed> $parameters
     */
    private function declaresIdempotencyKey(array $parameters): bool
    {
        foreach ($parameters as $given) {
            $parameter = $this->resolved($given, 'parameters');

            if (($parameter['in'] ?? null) === 'header' && strcasecmp(self::text($parameter['name'] ?? null), self::IDEMPOTENCY_HEADER) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<array-key, mixed> $operation
     *
     * @return list<string>
     */
    private function queryParameters(array $operation): array
    {
        $names = [];

        foreach (self::listOf($operation['parameters'] ?? null) as $given) {
            $parameter = $this->resolved($given, 'parameters');

            if (($parameter['in'] ?? null) === 'query') {
                $names[] = self::text($parameter['name'] ?? null);
            }
        }

        return $names;
    }

    /**
     * The status and the schema of every 2xx JSON answer of an operation.
     *
     * @param array<array-key, mixed> $operation
     *
     * @return list<array{int, mixed}>
     */
    private function answersOf(array $operation): array
    {
        $answers = [];

        foreach (self::map($operation['responses'] ?? null) as $status => $response) {
            $schema = $this->jsonSchemaOf($response, 'responses');

            if (str_starts_with((string) $status, '2') && $schema !== null) {
                $answers[] = [(int) $status, $schema];
            }
        }

        return $answers;
    }

    /**
     * The first step of a dotted path a schema has no property for, as the path up to it; null when it has all.
     */
    private function missingIn(mixed $schema, string $path): ?string
    {
        $walked = [];

        foreach (explode('.', $path) as $key) {
            $walked[] = $key;
            $properties = $this->objectOf($schema)['properties'];

            if (!\array_key_exists($key, $properties)) {
                return implode('.', $walked);
            }

            $schema = $properties[$key];
        }

        return null;
    }

    private function schemaName(mixed $schema): string
    {
        $reference = self::map($schema)['$ref'] ?? null;

        return \is_string($reference) ? basename($reference) : 'an answer written in place';
    }

    /**
     * The properties and the required list of an object schema, `allOf` merged.
     *
     * @return array{properties: array<array-key, mixed>, required: list<string>}
     */
    private function objectOf(mixed $given): array
    {
        $schema = $this->resolved($given, 'schemas');
        $properties = self::map($schema['properties'] ?? null);
        $required = array_map(self::text(...), self::listOf($schema['required'] ?? null));

        foreach (self::listOf($schema['allOf'] ?? null) as $part) {
            $inner = $this->objectOf($part);
            $properties = [...$properties, ...$inner['properties']];
            $required = [...$required, ...$inner['required']];
        }

        return ['properties' => $properties, 'required' => $required];
    }

    /**
     * A parameter, an answer or a schema with its `$ref` followed into that section of `components`.
     *
     * @return array<array-key, mixed>
     */
    private function resolved(mixed $given, string $section): array
    {
        self::assertIsArray($given, \sprintf('One of the %s of the spec is not a mapping.', $section));

        $reference = $given['$ref'] ?? null;

        if ($reference === null) {
            return $given;
        }

        $prefix = self::REFERENCE . $section . '/';

        self::assertIsString($reference);
        self::assertStringStartsWith($prefix, $reference);

        $target = self::map(self::map($this->spec()['components'] ?? null)[$section] ?? null)[substr($reference, \strlen($prefix))] ?? null;

        self::assertIsArray($target, 'The spec refers to ' . $reference . ', which it does not define.');

        return $this->resolved($target, $section);
    }

    /**
     * @return list<string>
     */
    private function specErrorCodes(): array
    {
        $enum = self::listOf(self::map(self::map(self::map($this->spec()['components'] ?? null)['schemas'] ?? null)['ErrorCode'] ?? null)['enum'] ?? null);

        self::assertNotSame([], $enum, 'The spec has no `ErrorCode` enum.');

        return array_map(self::text(...), $enum);
    }

    /**
     * @return list<string>
     */
    private static function tableCodes(): array
    {
        return array_map(strval(...), array_keys(self::map(self::json(self::ERROR_TABLE)['codes'] ?? null)));
    }

    /**
     * Code → the reason of the group it is listed under.
     *
     * @return array<string, string>
     */
    private static function unmappedCodes(): array
    {
        $codes = [];

        foreach (self::map(self::json(self::UNMAPPED_CODES)['groups'] ?? null) as $name => $group) {
            $group = self::map($group);

            foreach (self::listOf($group['codes'] ?? null) as $code) {
                self::assertArrayNotHasKey(self::text($code), $codes, \sprintf('%s is listed twice (second time under %s)', self::text($code), $name));

                $codes[self::text($code)] = self::text($group['reason'] ?? null);
            }
        }

        return $codes;
    }

    /**
     * @return array<string, array{operation: string, sdks: list<string>}>
     */
    private static function linesOfThisSdk(): array
    {
        return array_filter(self::callTable(), static function (array $line): bool {
            return \in_array(self::SDK, $line['sdks'], true);
        });
    }

    /**
     * @return array<string, array{operation: string, sdks: list<string>}>
     */
    private static function callTable(): array
    {
        $table = [];

        foreach (self::map(self::json(self::CALL_TABLE)['calls'] ?? null) as $name => $line) {
            $line = self::map($line);
            $table[(string) $name] = [
                'operation' => self::text($line['operation'] ?? null),
                'sdks' => array_map(self::text(...), self::listOf($line['sdks'] ?? null)),
            ];
        }

        self::assertNotSame([], $table, 'contract/calls.json has no `calls`.');

        return $table;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function json(string $path): array
    {
        self::assertFileExists($path, basename($path) . ' is not in contract/: the suite reads the contract where tests/Paths.php says.');

        return self::map(json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function spec(): array
    {
        if (self::$spec !== null) {
            return self::$spec;
        }

        $named = getenv(self::SPEC_VARIABLE);

        if (\is_string($named) === false || $named === '') {
            self::markTestSkipped('No OpenAPI spec to hold the SDK against: set ' . self::SPEC_VARIABLE . ' to the spec of the API.');
        }

        self::assertFileExists($named, self::SPEC_VARIABLE . ' names a file that is not there.');

        return self::$spec = self::parse($named);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function parse(string $path): array
    {
        $spec = self::map(Yaml::parseFile($path));

        self::assertArrayHasKey('paths', $spec, $path . ' is not an OpenAPI document.');

        return $spec;
    }

    /**
     * @param class-string $class
     */
    private static function short(string $class): string
    {
        return (new ReflectionClass($class))->getShortName();
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function map(mixed $value): array
    {
        return \is_array($value) ? $value : [];
    }

    /**
     * @return list<mixed>
     */
    private static function listOf(mixed $value): array
    {
        return \is_array($value) ? array_values($value) : [];
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
