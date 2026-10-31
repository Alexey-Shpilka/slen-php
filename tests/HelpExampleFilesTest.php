<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests;

use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The checks of the help examples that only read files, for the files of this
 * package: each `php.php` is there exactly when its `example.json` names php,
 * carries the regions the example lists, and has no region that shows what an
 * article must not teach or leans on a line the reader is not shown.
 *
 * The TypeScript suite makes the same checks for its `node.ts` and holds the
 * JSON of every example; the checks across languages (the same regions in
 * each, `languages` = the files, one `expect.json`) are `check-examples.mjs`
 * of the SDK's source repository. The rules are in `AGENTS.md` ("Help examples").
 */
class HelpExampleFilesTest extends TestCase
{
    private const EXAMPLES = Paths::EXAMPLES.'/help';

    private const REGION_START = '~^\s*// docs:start ([a-z][a-z0-9-]*)$~';

    private const REGION_END = '~^\s*// docs:end$~';

    private const REGION_MARKER = '~docs:(?:start|end)~';

    private const HOLDS = 'the holds left the SDK: a flow holds and settles the money of its model calls';

    private const GATEWAY = 'the gateway is called from inside a flow only: run the operation with `operations.execute`';

    private const PHP_ENTRY = 'the PHP SDK is built with `SlenSdk::create(new SlenConfig(…))`';

    // What no region may show: names on their way out of the SDKs or never in them, and the lines of the
    // harness, which mean nothing to a reader. The same list as the TypeScript suite's `NOT_SHOWN`.
    private const NOT_SHOWN = [
        '~waitUntilFinished~' => '`waitUntilFinished` is the pre-v7 wait — show `wait()`',
        '~\bisAllowed\b~' => '`isAllowed()` goes away with the `denied` status',
        '~[\'"]denied[\'"]~' => 'the `denied` status of a hold goes away',
        '~sg_test_~' => 'the test mode is not documented',
        '~dry_run~' => 'the test mode is not documented',
        '~SlenClient~' => 'there is no `SlenClient` in either SDK',
        '~L[I]MLY_|SP[E]NDGUARD_~' => 'the pre-rename environment names',
        '~X-L[i]mly-|X-Sp[e]ndGuard-~i' => 'the pre-rename header names',
        '~first-request-1~' => 'an idempotency key is derived from the unit of work, never a constant',
        '~localhost~' => 'an example names the production host, not the default of a development setup',
        '~\$offline\b~' => 'the `$offline` hand-over is a line of the harness — it stays outside every region',
        '~\bHarness:~' => 'a `// Harness:` comment marks a line the reader is not shown — it stays outside every region',
        '~\bdeclare\s+(?:const|let|var|function|global|module|namespace)\b~' => 'an ambient `declare` is there for the compiler — it stays outside every region',
        '~\$recorded\b|\bexport\b.*\brecorded\b~' => '`recorded` is what an example hands to the harness — it stays outside every region',
    ];

    // What the SDK no longer has. The same list as the TypeScript suite's `RETIRED`.
    private const RETIRED = [
        '~\bauthorizations\b~' => self::HOLDS,
        '~reservations\(~' => self::HOLDS,
        '~AuthorizeAiOperation~' => self::HOLDS,
        '~costEstimates~' => 'the estimates left the SDK',
        '~\bchat\.|\bchat\(\)~' => self::GATEWAY,
        '~\.run\(|\.stream\(~' => self::GATEWAY,
        '~responses\.create~' => self::GATEWAY,
        '~wrapOpenAI~' => self::GATEWAY,
        '~\boperations\.wait\b|\boperations\.execution\b|operations\(\)->get\(~' => 'a run is read through `executions`: `get`, `wait`, `trace`',
        '~new Client\(|new Config\(~' => self::PHP_ENTRY,
        '~defaultOperation~' => 'the SDK has no default operation: every run names its own',
        '~setLogger\(~' => 'a logger of its own is a copy of the client: `withLogger()`',
        '~Slen\\\\Sdk\\\\Shared\\\\~' => 'the public PHP classes are not under `Shared`: `Slen\Sdk\SlenSdk`, `Slen\Sdk\Exceptions\…`',
        '~\b(?:CustomerListFilters|AccountSettings|EntryFilters|PriceFilters)\b~' => 'the options are named as the PHP classes: `CustomerListOptions`, `AccountOptions`, `EntryListOptions`, `PriceListOptions`',
        '~\b(?:HealthDTO|PageDTO|CustomerPageDTO|CreditEntryPageDTO|CustomerDTO|CustomerAccountDTO|CreditEntryDTO|CreditPriceDTO|ExecutionDTO|ExecutionCostDTO|ExecutionCacheDTO|WaitingOnDTO|ExecutionErrorDTO|ExecutionStatusDTO|ExecutionTraceDTO|TraceNodeDTO|TraceEventDTO|TraceAttemptDTO|WebhookEventDTO|ExecutionFinishedEventDTO|CustomerBalanceLowEventDTO|ApprovalRequestedEventDTO|ApprovalDecidedEventDTO|UnknownEventDTO|SpanDTO)\b~' => 'a type of an answer is named as in TypeScript, without `DTO`: `Customer`, `ExecutionResult`, `Page`',
        '~->hasMore\(~' => 'a page has no `hasMore()`: the last page is the one whose `nextCursor` is null',
    ];

    // What no file of an example names, in a region or out of one: the address of the API is the SDK's
    // constant, and a key is shown as `sl_live_…`.
    private const NAMED_NOWHERE = [
        '~base[_ ]?url~i' => "the address of the API is the SDK's constant: no example passes it or mentions it",
        '~\bsg_(?:live|test)_~' => 'a key is shown as `sl_live_…`',
    ];

    // PHP that does not parse on the floor composer.json declares (8.3); CI's `php-floor` lints the files on a
    // real 8.3, this is the early warning in every run.
    private const ABOVE_THE_FLOOR = [
        '~\bnew\s+[\w\\\\]+\s*\([^()]*\)\s*(?:\?->|->|::)~' => '`new Foo()->bar()` is PHP 8.4 — on the floor (8.3) it is `(new Foo())->bar()`',
    ];

    #[DataProvider('everyExampleProvider')]
    public function testAnExampleHasAPhpFileExactlyWhenItNamesPhp(string $id): void
    {
        $named = in_array('php', self::example($id)['languages'], true);

        self::assertSame($named, is_file(self::EXAMPLES.'/'.$id.'/php.php'));
    }

    #[DataProvider('phpExampleProvider')]
    public function testAnExampleNamesNeitherTheAddressOfTheApiNorAnEarlierKeyInItsFiles(string $id): void
    {
        $found = [];

        foreach (['example.json', 'expect.json', 'php.php'] as $name) {
            foreach (self::matching(self::NAMED_NOWHERE, (string) file_get_contents(self::EXAMPLES.'/'.$id.'/'.$name)) as $why) {
                $found[] = $name.': '.$why;
            }
        }

        self::assertSame([], $found);
    }

    #[DataProvider('phpExampleProvider')]
    public function testAnExampleCarriesItsRegionsAndNothingARegionMayNotShowOrLeanOn(string $id): void
    {
        $source = (string) file_get_contents(self::EXAMPLES.'/'.$id.'/php.php');
        $regions = self::partsOf($source)['regions'];

        self::assertSame(self::example($id)['regions'], array_keys($regions));
        self::assertSame([], self::hiddenReads($source));
        self::assertSame([], self::matching(self::ABOVE_THE_FLOOR, $source));

        foreach ($regions as $name => $text) {
            self::assertSame([], self::matching(self::NOT_SHOWN, $text), 'region "'.$name.'"');
            self::assertSame([], self::matching(self::RETIRED, $text), 'region "'.$name.'"');
        }
    }

    public function testTheRegionsOfAFileAreReadInOrder(): void
    {
        $source = implode("\n", ['hidden();', '// docs:start client', '$a = 1;', '// docs:end', '    // docs:start send', '    go();', '    // docs:end']);

        self::assertSame(['client' => '$a = 1;', 'send' => '    go();'], self::partsOf($source)['regions']);
        self::assertSame(['hidden();'], self::partsOf($source)['outside']);
    }

    /**
     * @return Iterator<string, array{string, string}>
     */
    public static function ambiguousMarkersProvider(): Iterator
    {
        yield 'inside another' => ["// docs:start a\n// docs:start b\nx\n// docs:end", 'starts inside'];
        yield 'twice' => ["// docs:start a\nx\n// docs:end\n// docs:start a\ny\n// docs:end", 'twice'];
        yield 'never started' => ["x\n// docs:end", 'never started'];
        yield 'never closed' => ["// docs:start a\nx", 'never closed'];
        yield 'empty' => ["// docs:start a\n\n// docs:end", 'is empty'];
        yield 'a name in capitals' => ["// docs:start Client\nx\n// docs:end", 'not a region marker'];
        yield 'not a line of its own' => ['go(); // docs:end', 'not a region marker'];
    }

    #[DataProvider('ambiguousMarkersProvider')]
    public function testMarkersThatCanBeReadTwoWaysAreRefused(string $source, string $problem): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($problem);

        self::partsOf($source);
    }

    public function testTheNamesARegionMayNotShowAreKnown(): void
    {
        self::assertCount(1, self::matching(self::NOT_SHOWN, "if (\$authorization->status === 'denied') {"));
        self::assertCount(1, self::matching(self::NOT_SHOWN, '$slen->operations()->waitUntilFinished($id);'));
        self::assertCount(1, self::matching(self::NOT_SHOWN, '$client = $offline($client);'));
        self::assertCount(1, self::matching(self::NOT_SHOWN, '// Harness: from here on the client answers from the test double.'));
        self::assertCount(1, self::matching(self::NOT_SHOWN, '$recorded = $http->requests;'));
        self::assertSame([], self::matching(self::NOT_SHOWN, '} catch (PolicyDeniedException $e) {'));
        // PHP's own `declare`, a double the example brought, a word of a comment.
        self::assertSame([], self::matching(self::NOT_SHOWN, 'declare(strict_types=1);'));
        self::assertSame([], self::matching(self::NOT_SHOWN, '$sent = $http->lastRequest();'));
        self::assertSame([], self::matching(self::NOT_SHOWN, '// What the double recorded, offline.'));
    }

    public function testWhatTheOldSdkShowedAndTheNewOneDoesNotIsKnown(): void
    {
        $old = [
            '$client->reservations()->get($id);',
            '$client->chat()->create($params);',
            '$client->operations()->get($id);',
            '$client = new Client(new Config(apiKey: $key));',
            '$slen->setLogger($logger);',
            'use Slen\\Sdk\\Shared\\SlenSdk;',
            '$filters = new CustomerListFilters(limit: 10);',
            'use Slen\\Sdk\\Modules\\Operations\\Data\\ExecutionDTO;',
            'function shown(UnknownEventDTO $event): void {}',
            'while ($page->hasMore()) {',
        ];

        foreach ($old as $line) {
            self::assertCount(1, self::matching(self::RETIRED, $line), $line);
        }

        self::assertSame([], self::matching(self::RETIRED, '$slen = SlenSdk::create(new SlenConfig(apiKey: $key));'));
        self::assertSame([], self::matching(self::RETIRED, '$sdkOfThisRequest = $slen->withLogger($requestLogger);'));
        self::assertSame([], self::matching(self::RETIRED, 'use Slen\\Sdk\\Modules\\Customers\\Options\\MovementOptions;'));
        self::assertSame([], self::matching(self::RETIRED, 'use Slen\\Sdk\\Modules\\Operations\\Data\\ExecutionResult;'));
        self::assertSame([], self::matching(self::RETIRED, 'function shown(UnknownWebhookEvent $event): void {}'));
    }

    public function testWhatNoFileOfAnExampleNamesIsKnown(): void
    {
        $named = [
            "\$slen = SlenSdk::create(new SlenConfig(apiKey: \$key, baseUrl: 'https://api.test'));",
            "config(['slen-sdk.base_url' => null]);",
            'SLEN_BASE_URL=https://api.test',
            '"summary": "A client with a base URL of its own."',
            "\$slen = SlenSdk::create(new SlenConfig(apiKey: 'sg_live_9f2c'));",
        ];

        foreach ($named as $line) {
            self::assertCount(1, self::matching(self::NAMED_NOWHERE, $line), $line);
        }

        self::assertSame([], self::matching(self::NAMED_NOWHERE, "\$slen = SlenSdk::create(new SlenConfig(apiKey: 'sl_live_9f2c'));"));
        self::assertSame([], self::matching(self::NAMED_NOWHERE, '"POST https://api.slen.ai/api/v1/operations/execute"'));
    }

    public function testARegionThatReadsWhatOnlyALineOutsideTheRegionsBringsInIsReported(): void
    {
        $source = implode("\n", [
            '<?php',
            'use Slen\\Sdk\\Exception\\SlenException;',
            '// docs:start client',
            'use Slen\\Sdk\\Client;',
            '$client = new Client($config);',
            '// docs:end',
            '$client = $offline($client);',
            "\$usage = ['input_tokens' => 1180];",
            '// docs:start capture',
            "try { \$client->capture(\$id, ['input_tokens' => \$usage['input_tokens']]); } catch (SlenException \$e) {}",
            '// docs:end',
        ]);

        self::assertSame(
            [
                'region "capture" reads SlenException, which only a line outside the regions brings in',
                'region "capture" reads $usage, which only a line outside the regions brings in',
            ],
            self::hiddenReads($source),
        );
    }

    public function testARegionMayReadWhatARegionBringsInAndAMemberThatSharesAHiddenName(): void
    {
        $source = implode("\n", [
            '// docs:start client',
            '$client = new Client($config);',
            '// docs:end',
            '$client = $offline($client);',
            '$usage = 1;',
            '// docs:start call',
            "echo \$client->usage, \$run->usage, \$usageTotal, 'usage';",
            '// docs:end',
        ]);

        self::assertSame([], self::hiddenReads($source));
    }

    public function testThePhpAnExampleMayNotBeWrittenInIsKnown(): void
    {
        self::assertCount(1, self::matching(self::ABOVE_THE_FLOOR, "\$config = new ReflectionProperty(Client::class, 'config')->getValue(\$client);"));
        self::assertCount(1, self::matching(self::ABOVE_THE_FLOOR, '$http = new FakeHttpClient()->pushResponse(200, []);'));
        self::assertSame([], self::matching(self::ABOVE_THE_FLOOR, '$http = (new FakeHttpClient())->pushResponse(200, []);'));
        self::assertSame([], self::matching(self::ABOVE_THE_FLOOR, "\$client = new Client(\n    new Config(apiKey: 'k'),\n    \$http,\n);\n\$client->health();"));
    }

    /**
     * @return Iterator<string, array{string}>
     */
    public static function everyExampleProvider(): Iterator
    {
        foreach (self::ids() as $id) {
            yield $id => [$id];
        }
    }

    /**
     * @return Iterator<string, array{string}>
     */
    public static function phpExampleProvider(): Iterator
    {
        foreach (self::ids() as $id) {
            if (in_array('php', self::example($id)['languages'], true) === true) {
                yield $id => [$id];
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function ids(): array
    {
        if (is_dir(self::EXAMPLES) === false) {
            throw new RuntimeException('examples/help is not where tests/Paths.php says it is.');
        }

        $ids = array_values(array_filter(scandir(self::EXAMPLES), static function (string $name): bool {
            return $name[0] !== '.' && is_dir(self::EXAMPLES.'/'.$name) === true;
        }));
        sort($ids);

        return $ids;
    }

    /**
     * @return array{languages: list<string>, regions: list<string>}
     */
    private static function example(string $id): array
    {
        /** @var array{languages: list<string>, regions: list<string>} */
        return json_decode((string) file_get_contents(self::EXAMPLES.'/'.$id.'/example.json'), true, 64, JSON_THROW_ON_ERROR);
    }

    /**
     * What the patterns of a list say about the text, one entry per pattern that matches.
     *
     * @param array<string, string> $patterns
     *
     * @return list<string>
     */
    private static function matching(array $patterns, string $text): array
    {
        $found = [];

        foreach ($patterns as $pattern => $why) {
            if (preg_match($pattern, $text) === 1) {
                $found[] = $why;
            }
        }

        return $found;
    }

    /**
     * A source file taken apart: its regions in order — the lines between `// docs:start <name>` and
     * `// docs:end` — and every line that is in none of them. Throws on anything a reader of the markers
     * could take two ways.
     *
     * @return array{regions: array<string, string>, outside: list<string>}
     */
    private static function partsOf(string $source): array
    {
        $regions = [];
        $outside = [];
        $open = null;
        $lines = [];

        foreach (explode("\n", $source) as $index => $line) {
            $at = 'line '.($index + 1);
            $started = preg_match(self::REGION_START, $line, $match) === 1 ? $match[1] : null;
            $ended = preg_match(self::REGION_END, $line) === 1;

            if ($started !== null) {
                self::refuse($open !== null, sprintf('%s: region "%s" starts inside region "%s".', $at, $started, (string) $open));
                self::refuse(isset($regions[$started]) === true, sprintf('%s: region "%s" is there twice.', $at, $started));
                $open = $started;
                $lines = [];

                continue;
            }

            if ($ended === true) {
                self::refuse($open === null, $at.': a region ends that never started.');
                self::refuse(trim(implode('', $lines)) === '', sprintf('%s: region "%s" is empty.', $at, (string) $open));
                $regions[(string) $open] = implode("\n", $lines);
                $open = null;

                continue;
            }

            self::refuse(preg_match(self::REGION_MARKER, $line) === 1, $at.': not a region marker — write "// docs:start <name>" or "// docs:end" on a line of its own.');

            if ($open !== null) {
                $lines[] = $line;

                continue;
            }

            $outside[] = $line;
        }

        self::refuse($open !== null, sprintf('Region "%s" is never closed.', (string) $open));

        return ['regions' => $regions, 'outside' => $outside];
    }

    private static function refuse(bool $wrong, string $why): void
    {
        if ($wrong === true) {
            throw new RuntimeException($why);
        }
    }

    /**
     * Where a region leans on a line the reader is not shown: it reads a name that only a line outside every
     * region brings in. A name a region brings in itself is the reader's, whatever the harness does to it afterwards.
     *
     * @return list<string>
     */
    private static function hiddenReads(string $source): array
    {
        $parts = self::partsOf($source);
        $shown = self::namesBroughtIn(explode("\n", implode("\n", $parts['regions'])));
        $hidden = array_values(array_diff(array_unique(self::namesBroughtIn($parts['outside'])), $shown));
        $found = [];

        foreach ($parts['regions'] as $region => $text) {
            foreach ($hidden as $name) {
                if (self::reads($text, $name) === true) {
                    $found[] = sprintf('region "%s" reads %s, which only a line outside the regions brings in', $region, $name);
                }
            }
        }

        return $found;
    }

    /**
     * The names the lines bring in: a variable assigned at the start of a line, what a one-line `use` names.
     *
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private static function namesBroughtIn(array $lines): array
    {
        $names = [];

        foreach ($lines as $line) {
            if (preg_match('~^\s*(\$[A-Za-z_]\w*)\s*=(?![=>])~', $line, $assigned) === 1) {
                $names[] = $assigned[1];
            }

            if (preg_match('~^\s*use\s+(?:function\s+|const\s+)?([\w\\\\]+?)(?:\s+as\s+(\w+))?\s*;~', $line, $used) === 1) {
                $parts = explode('\\', $used[1]);
                $names[] = ($used[2] ?? '') !== '' ? $used[2] : end($parts);
            }
        }

        return $names;
    }

    /** Whether the text reads a name as a name of its own: not a part of a longer one, a member or a namespace's. */
    private static function reads(string $text, string $name): bool
    {
        $before = str_starts_with($name, '$') === true ? '\w$' : '\w$\\\\>:';

        return preg_match('~(?<!['.$before.'])'.preg_quote($name, '~').'(?![\w$])~', $text) === 1;
    }
}
