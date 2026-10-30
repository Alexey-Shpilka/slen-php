<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slen\Sdk\Tests\Paths;

/**
 * The table of the README that says which exception may have cost money, held to the error contract; and what
 * the README never shows.
 */
final class ReadmeTest extends TestCase
{
    private const string README = __DIR__ . '/../../README.md';

    private const string CONTRACT = Paths::CONTRACT . '/error-codes.json';

    /** The names of the types of answers before they were named as in TypeScript. */
    private const array RETIRED_TYPES = [
        'HealthDTO', 'PageDTO', 'CustomerPageDTO', 'CreditEntryPageDTO', 'CustomerDTO', 'CustomerAccountDTO',
        'CreditEntryDTO', 'CreditPriceDTO', 'ExecutionDTO', 'ExecutionCostDTO', 'ExecutionCacheDTO', 'WaitingOnDTO',
        'ExecutionErrorDTO', 'ExecutionStatusDTO', 'ExecutionTraceDTO', 'TraceNodeDTO', 'TraceEventDTO',
        'TraceAttemptDTO', 'WebhookEventDTO', 'ExecutionFinishedEventDTO', 'CustomerBalanceLowEventDTO',
        'ApprovalRequestedEventDTO', 'ApprovalDecidedEventDTO', 'UnknownEventDTO', 'SpanDTO',
    ];

    /**
     * The row a caller trusts with "nothing was spent" for the failed model call lists exactly the codes of the
     * type `Provider`, so a code that joins or leaves the type reaches it. The row beside it, for everything else
     * that lands in the type, is the only place the caller learns that the fallback is not safe.
     */
    public function testTheReadmeTrustsAProviderFailureOnlyUnderTheProviderCodesOfTheContract(): void
    {
        $rows = self::rowsStartingWith('| `ProviderException` with ');

        self::assertCount(2, $rows, "README.md: one row for a ProviderException with a provider's code, one for any other.");

        preg_match_all('/`([a-z]+(?:_[a-z]+)+)`/', explode('|', $rows[0])[1] ?? '', $named);
        $listed = array_values(array_unique($named[1]));
        $codes = self::providerCodes();
        sort($listed);
        sort($codes);

        self::assertSame($codes, $listed);
        self::assertStringContainsString('no —', $rows[0]);
        self::assertStringContainsString('errorCode === null', $rows[1]);
        self::assertStringContainsString('**unknown.**', $rows[1]);
        self::assertStringContainsString('`OperationUncertainException`', $rows[1]);
    }

    /**
     * A run is several model calls: one that throws after the first was paid for answers with the exception of the
     * node that threw. The row of the flow names them, or the table sends a caller to pay for the first node twice.
     */
    public function testTheReadmeTakesNoErrorOutOfAFlowForNothingSpent(): void
    {
        $rows = self::rowsStartingWith('| `FlowRuntimeException` — ');

        self::assertCount(1, $rows, 'README.md: one row for what an error out of a run says about money.');

        foreach (['operations()->execute()', '`ProviderException`', '`SpendRefusedException`', '`PolicyDeniedException`', 'were paid for'] as $said) {
            self::assertStringContainsString($said, $rows[0]);
        }
    }

    /**
     * The address of the API is the SDK's constant, a parameter for tests and a local stack only: the README
     * neither passes it nor names it, in the code or in the text.
     */
    public function testTheReadmeNamesNoAddressOfTheApi(): void
    {
        self::assertDoesNotMatchRegularExpression('/base[_ ]?url/i', (string) file_get_contents(self::README));
    }

    /**
     * The README shows the types of answers as the code has them, and pages without `hasMore()`.
     */
    public function testTheReadmeNamesNoRetiredType(): void
    {
        $readme = (string) file_get_contents(self::README);

        $retired = '/\b(?:' . implode('|', self::RETIRED_TYPES) . ')\b|->hasMore\(/';

        self::assertDoesNotMatchRegularExpression($retired, $readme);
        self::assertMatchesRegularExpression($retired, 'foreach ($page->data as $customer) {} $page->hasMore();');
        self::assertMatchesRegularExpression($retired, '$run instanceof ExecutionDTO');
        self::assertDoesNotMatchRegularExpression($retired, '$run instanceof ExecutionResult');
    }

    /**
     * Keys are sl_live_...; one issued earlier as sg_live_... keeps working, and is not what a reader is shown.
     */
    public function testTheReadmeShowsAKeyOfToday(): void
    {
        $readme = (string) file_get_contents(self::README);

        self::assertDoesNotMatchRegularExpression('/\bsg_(?:live|test)_/', $readme);
        self::assertStringContainsString('`sl_live_…`', $readme);
    }

    /**
     * The versions a caller can count on, as composer.json declares them.
     */
    public function testTheReadmeSaysWhichVersionsThePackageRunsOn(): void
    {
        $sections = preg_split('/^## /m', (string) file_get_contents(self::README));
        $compatibility = array_values(array_filter($sections === false ? [] : $sections, static function (string $section): bool {
            return str_starts_with($section, "Compatibility\n");
        }));

        self::assertCount(1, $compatibility);

        foreach (['8.3, 8.4, 8.5', 'Laravel | 11 and 12', 'PSR-18', 'bin/floor.php'] as $said) {
            self::assertStringContainsString($said, $compatibility[0]);
        }
    }

    /**
     * A worker shares one SDK: the section says how, and what Octane needs of it.
     */
    public function testTheReadmeTellsALongRunningWorkerHowToShareTheSdk(): void
    {
        $sections = preg_split('/^## /m', (string) file_get_contents(self::README));
        $workers = array_values(array_filter($sections === false ? [] : $sections, static function (string $section): bool {
            return str_starts_with($section, 'Long-running workers (FrankenPHP, Octane, RoadRunner, Swoole)');
        }));

        self::assertCount(1, $workers);

        $musts = ['withLogger(', 'executeAsync()', 'execution.finished', 'octane.warm', 'octane:reload', 'One `SlenSdk` per API key', 'FakeHttpClient'];

        foreach ($musts as $said) {
            self::assertStringContainsString($said, $workers[0]);
        }
    }

    /**
     * The code a reader copies branches with an early return, `match (true)` or independent `if`s.
     */
    public function testNoCodeOfTheReadmeBranchesWithElse(): void
    {
        $readme = (string) file_get_contents(self::README);

        self::assertGreaterThan(10, \count(self::codeBlocks($readme)), 'the test has to be reading the code of the README');
        self::assertSame([], self::branchingWithElse($readme));
    }

    public function testTheCheckOfTheCodeFindsAnElseInABlockAndNotInTheProse(): void
    {
        $block = "if (\$paid) {\n    echo 1;\n} elseif (\$due) {\n    echo 2;\n}\n";
        $markdown = "Anything else is refused.\n\n```php\n" . $block . "```\n\n```php\necho 3;\n```\n";

        self::assertSame([$block], self::branchingWithElse($markdown));
    }

    /**
     * @return list<string>
     */
    private static function codeBlocks(string $markdown): array
    {
        preg_match_all('/^```\w*\n(.*?)^```$/ms', $markdown, $blocks);

        return $blocks[1];
    }

    /**
     * @return list<string>
     */
    private static function branchingWithElse(string $markdown): array
    {
        return array_values(array_filter(self::codeBlocks($markdown), static function (string $block): bool {
            return preg_match('/\belse(?:if)?\b/', $block) === 1;
        }));
    }

    /**
     * @return list<string>
     */
    private static function rowsStartingWith(string $start): array
    {
        $lines = explode("\n", (string) file_get_contents(self::README));

        return array_values(array_filter($lines, static function (string $line) use ($start): bool {
            return str_starts_with($line, $start);
        }));
    }

    /**
     * @return list<string>
     */
    private static function providerCodes(): array
    {
        /** @var array{codes: array<string, array{type: string}>} $contract */
        $contract = json_decode((string) file_get_contents(self::CONTRACT), true, 512, JSON_THROW_ON_ERROR);

        return array_keys(array_filter($contract['codes'], static function (array $entry): bool {
            return $entry['type'] === 'Provider';
        }));
    }
}
