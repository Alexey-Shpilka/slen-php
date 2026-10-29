<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit;

use FilesystemIterator;
use PhpToken;
use PHPUnit\Framework\TestCase;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Slen\Sdk\Tests\Paths;
use SplFileInfo;

/**
 * Comments in shipped code say only what has to be seen at the line: six lines at most, no history,
 * no numbers of findings or decisions, no links into the plans. The reasons live in AGENTS.md.
 * No file of the package names what stays internal: a public repository is made of it as it is.
 */
final class SourceHygieneTest extends TestCase
{
    private const int MAX_COMMENT_LINES = 6;

    /** A line that starts with //, /* or * counts as a comment line. */
    private const float MAX_COMMENT_SHARE = 0.13;

    private const array SHIPPED = ['src', 'config'];

    private const string REFERENCE = '~docs/v\d|(?<![\p{L}\p{N}_])[НРПЗВФХ](?:-\d|\d+\.\d)~iu';

    private const string HISTORY = '~\bused to\b|\buntil v\d|\bsince v\d~i';

    /**
     * The patterns of check-public-tree.sh, the guard a public tree is made behind; each is spelt so it does not find itself.
     */
    private const array INTERNAL = [
        '~/h[o]me/~i' => 'a path of a machine',
        '~d[o]cs/v\d~i' => 'a link to the plans',
        '~slen\.(?:backend|frontend|infra|docs|sdk)~i' => 'a private repository',
        '~backend/[o]penapi~i' => 'the tree of the backend',
        '~DEPLOY_[K]EY~i' => 'a deploy key',
        '~localhost:3808[0]~i' => 'the local stack',
        '~sn[o]vio|sp[e]ndguard|l[i]mly~i' => 'an earlier name',
        '~(?<![\p{L}\p{N}_])[НРПЗВФХ](?:-\d|\d+\.\d)~iu' => 'a number of a finding, a decision or a task',
        '~sl_live_[A-Za-z0-9]{20,}~' => 'a key that looks real',
    ];

    /** What the package's tools leave in it and a public tree never gets. */
    private const array LEFT_BEHIND = ['vendor', 'composer.lock', '.phpunit.cache'];

    public function testShippedCodeKeepsItsCommentsShortAndFreeOfHistory(): void
    {
        $files = self::checkedFiles();
        $offences = [];

        foreach ($files as $relative => $path) {
            foreach (self::offencesIn((string) file_get_contents($path)) as $offence) {
                $offences[] = $relative . ': ' . $offence;
            }
        }

        self::assertGreaterThan(30, \count($files), 'the test has to be reading the new code');
        self::assertSame([], $offences, implode("\n", $offences));
    }

    public function testNoFileOfThePackageNamesWhatStaysInternal(): void
    {
        $files = self::packageFiles();
        $found = [];

        foreach ($files as $relative => $path) {
            foreach (self::internalIn((string) file_get_contents($path)) as $finding) {
                $found[] = $relative . ': ' . $finding;
            }
        }

        self::assertArrayHasKey('README.md', $files, 'the test has to be reading the whole package');
        self::assertArrayHasKey('tests/Unit/SourceHygieneTest.php', $files);
        self::assertGreaterThan(200, \count($files));
        self::assertSame([], $found, implode("\n", $found));
    }

    public function testTheInternalPatternsFindWhatTheyAreForAndNothingElse(): void
    {
        $text = implode("\n", [
            \sprintf('see /%s/someone/x', 'home'),
            \sprintf('%s/v35/02-spec.md', 'docs'),
            \sprintf('%s.frontend', 'slen'),
            \sprintf('backend/%s/v1.yaml', 'openapi'),
            \sprintf('SDK_DEPLOY_%s', 'KEY'),
            \sprintf('http://localhost:%d', 38080),
            \sprintf('Spend%s', 'Guard'),
            "fixes \u{41D}-17",
            "dropped in \u{412}3.1",
            'sl_live_' . str_repeat('x', 20),
            'H-1 is not Cyrillic; https://api.test; sl_live_…; docs/configuration; v0.3.0 on PHP 8.4, 1.2.3',
        ]);

        self::assertSame(
            [
                'line 1: a path of a machine',
                'line 2: a link to the plans',
                'line 3: a private repository',
                'line 4: the tree of the backend',
                'line 5: a deploy key',
                'line 6: the local stack',
                'line 7: an earlier name',
                'line 8: a number of a finding, a decision or a task',
                'line 9: a number of a finding, a decision or a task',
                'line 10: a key that looks real',
            ],
            self::internalIn($text),
        );
    }

    public function testCommentsAreAtMostAThirteenthOrSoOfTheLinesOfTheSource(): void
    {
        $lines = 0;
        $comments = 0;

        foreach (self::filesUnder(Paths::PACKAGE . '/src') as $path) {
            foreach (explode("\n", (string) file_get_contents($path)) as $line) {
                ++$lines;
                $comments += (int) preg_match('~^\s*(//|/\*|\*)~', $line);
            }
        }

        self::assertLessThanOrEqual(self::MAX_COMMENT_SHARE, $comments / $lines, \sprintf('%d comment lines of %d', $comments, $lines));
    }

    public function testNoCodeBranchesWithElseOrOpensAConditionWithANegation(): void
    {
        $files = [...self::checkedFiles(), ...self::filesUnder(Paths::PACKAGE . '/bin'), ...self::examples(Paths::EXAMPLES . '/help')];
        $offences = [];

        foreach ($files as $relative => $path) {
            foreach (self::branchesIn((string) file_get_contents($path)) as $offence) {
                $offences[] = $relative . ': ' . $offence;
            }
        }

        self::assertGreaterThan(40, \count($files), 'the test has to be reading the code, the tools and the examples');
        self::assertSame([], $offences, 'An early return, a match or independent ifs; a condition compares with === false.');
    }

    public function testTheBranchReaderFindsElseAndANegatedIfAndNothingElse(): void
    {
        $code = implode("\n", [
            '<?php',
            'if ($a) {',
            '} elseif ($b) {',
            '} else {',
            '}',
            'if ( !$c) {',
            '}',
            'if ($d !== false && !$e) {',
            '}',
            '// else, if (!$f)',
        ]);

        self::assertSame(['line 3: elseif', 'line 4: else', 'line 6: if (!'], self::branchesIn($code));
    }

    public function testADocblockSaysWhatItMayAndNoMore(): void
    {
        $offences = [];
        $files = self::filesUnder(Paths::PACKAGE . '/src');

        foreach ($files as $relative => $path) {
            foreach (self::docblockOffencesIn((string) file_get_contents($path)) as $offence) {
                $offences[] = $relative . ': ' . $offence;
            }
        }

        self::assertGreaterThan(100, \count($files), 'the test has to be reading the source');
        self::assertSame([], $offences, 'Internal: tags only. Public class or method: one line, then tags; @param says more only there.');
    }

    public function testTheDocblockReaderKnowsWhatEachDocblockMaySay(): void
    {
        $internal = implode("\n", [
            '<?php',
            '/** @internal */',
            'final class A',
            '{',
            '    /** Reads the thing. */',
            '    public function read(): void {}',
            '',
            '    /** @param int $b the number of things */',
            '    public function count(int $b): void {}',
            '}',
        ]);
        $public = implode("\n", [
            '<?php',
            '/**',
            ' * One line.',
            ' * Two lines.',
            ' */',
            'final class B',
            '{',
            '    /**',
            '     * Reads it.',
            '     *',
            '     * @param int $a 1 to 100',
            '     * @throws E when it has to,',
            '     *           and this goes on',
            '     */',
            '    public function read(int $a): void {}',
            '',
            '    /** Keeps the count. */',
            '    private int $count = 0;',
            '',
            '    /** @param int $b a number */',
            '    private function count(int $b): void {}',
            '}',
        ]);

        self::assertSame(['line 5: prose in an internal docblock', 'line 8: a described @param outside a public method'], self::docblockOffencesIn($internal));
        self::assertSame(
            [
                'line 2: 2 lines of prose',
                'line 17: prose in a docblock of neither a class nor a public method',
                'line 20: a described @param outside a public method',
            ],
            self::docblockOffencesIn($public),
        );
    }

    public function testTheReaderFindsWhatItIsFor(): void
    {
        $seven = "<?php\n/**\n * 1\n * 2\n * 3\n * 4\n * 5\n * 6\n * 7\n */\nclass A {}";
        $sevenSlashes = "<?php\n// 1\n// 2\n// 3\n// 4\n// 5\n// 6\n// 7\n\$a = 1;";

        self::assertSame(['line 2: a comment of 7 lines'], self::offencesIn($seven));
        self::assertSame(['line 2: a comment of 7 lines'], self::offencesIn($sevenSlashes));
        self::assertSame(['line 2: a reference to docs, findings or decisions'], self::offencesIn(\sprintf("<?php\n// see %s/v24/README.md\n", 'docs')));
        self::assertSame(['line 2: a reference to docs, findings or decisions'], self::offencesIn("<?php\n// fixes \u{41D}-17\n"));
        self::assertSame(['line 2: a reference to docs, findings or decisions'], self::offencesIn("<?php\n\$a = '\u{417}-24';\n"));
        self::assertSame(['line 2: a reference to docs, findings or decisions'], self::offencesIn("<?php\n// accepted in \u{412}-29, \u{425}-20\n"));
        self::assertSame(['line 2: a reference to docs, findings or decisions'], self::offencesIn("<?php\n// dropped in \u{412}3.1\n"));
        self::assertSame([], self::offencesIn("<?php\n// as of 0.3.0, on PHP 8.4\n"));
        self::assertSame(['line 2: history'], self::offencesIn("<?php\n// this used to retry\n"));
        self::assertSame(['line 2: history'], self::offencesIn("<?php\n// Since v24 the key is kept\n"));
        self::assertSame(['line 2: history'], self::offencesIn("<?php\n// kept until v30\n"));
    }

    public function testTheReaderLetsShortCommentsAndTagsThrough(): void
    {
        $docblock = implode("\n", [
            '<?php',
            '/**',
            ' * One.',
            ' * Two.',
            ' *',
            ' * @param int $a',
            ' * @param int $b',
            ' * @param int $c',
            ' * @param int $d',
            ' * @param int $e',
            ' * @return int',
            ' * @throws E',
            ' */',
            'function f(int $a): int { return $a; }',
        ]);
        $apart = "<?php\n// 1\n// 2\n// 3\n\$a = 1;\n// 4\n// 5\n// 6\n// 7\n\$b = 2;";

        self::assertSame([], self::offencesIn($docblock));
        self::assertSame([], self::offencesIn($apart));
        self::assertSame([], self::offencesIn("<?php\n// the key is never logged; PSR-3 v3\n\$a = 'H-1 is not Cyrillic';\n"));
    }

    /**
     * @return array<string, string> the path in the package => the real path
     */
    private static function packageFiles(): array
    {
        $root = (string) realpath(Paths::PACKAGE);
        $kept = new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            static function (mixed $file) use ($root): bool {
                return $file instanceof SplFileInfo === true
                    && \in_array(substr($file->getPathname(), \strlen($root) + 1), self::LEFT_BEHIND, true) === false;
            },
        );
        $files = [];

        foreach (new RecursiveIteratorIterator($kept) as $file) {
            if ($file instanceof SplFileInfo === true) {
                $files[substr($file->getPathname(), \strlen($root) + 1)] = $file->getPathname();
            }
        }

        ksort($files);

        return $files;
    }

    /**
     * @return list<string> as "line N: what"
     */
    private static function internalIn(string $text): array
    {
        $found = [];

        foreach (explode("\n", $text) as $index => $line) {
            foreach (self::INTERNAL as $pattern => $what) {
                if (preg_match($pattern, $line) === 1) {
                    $found[] = \sprintf('line %d: %s', $index + 1, $what);
                }
            }
        }

        return $found;
    }

    /**
     * @return array<string, string> the path in the package => the real path
     */
    private static function checkedFiles(): array
    {
        $root = \dirname(__DIR__, 2);
        $files = [];

        foreach (self::SHIPPED as $directory) {
            $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS));

            foreach ($walk as $file) {
                if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }

                $files[substr($file->getPathname(), \strlen($root) + 1)] = $file->getPathname();
            }
        }

        ksort($files);

        return $files;
    }

    /**
     * @return list<string> as "line N: what"
     */
    private static function docblockOffencesIn(string $code): array
    {
        $tokens = array_values(array_filter(PhpToken::tokenize($code), static function (PhpToken $phpToken): bool {
            return $phpToken->is([T_WHITESPACE, T_COMMENT]) === false;
        }));
        $classInternal = false;
        $offences = [];

        foreach ($tokens as $index => $phpToken) {
            if ($phpToken->is(T_DOC_COMMENT) === false) {
                continue;
            }

            [$kind, $public] = self::documented($tokens, $index + 1);
            $internal = str_contains($phpToken->text, '@internal');

            if ($kind === 'class') {
                $classInternal = $internal;
            }

            $mayTalk = $classInternal === false && $internal === false && ($kind === 'class' || ($kind === 'method' && $public === true));
            [$prose, $describedParams] = self::read($phpToken->text);

            if ($mayTalk === false && $prose > 0) {
                $where = $classInternal === true || $internal === true ? 'an internal docblock' : 'a docblock of neither a class nor a public method';
                $offences[] = \sprintf('line %d: prose in %s', $phpToken->line, $where);
            }

            if ($mayTalk === true && $prose > 1) {
                $offences[] = \sprintf('line %d: %d lines of prose', $phpToken->line, $prose);
            }

            if ($describedParams > 0 && ($mayTalk === false || $kind !== 'method')) {
                $offences[] = \sprintf('line %d: a described @param outside a public method', $phpToken->line);
            }
        }

        return $offences;
    }

    /**
     * What the docblock before $index documents: a class, a method (and whether it is public), or anything else.
     *
     * @param list<PhpToken> $tokens
     *
     * @return array{string, bool}
     */
    private static function documented(array $tokens, int $index): array
    {
        $public = true;

        for ($at = $index; isset($tokens[$at]) === true; ++$at) {
            $phpToken = $tokens[$at];

            if ($phpToken->is(T_ATTRIBUTE) === true) {
                $at = self::closingBracket($tokens, $at);

                continue;
            }

            if ($phpToken->is([T_PRIVATE, T_PROTECTED]) === true) {
                $public = false;
            }

            if ($phpToken->is([T_PUBLIC, T_PRIVATE, T_PROTECTED, T_STATIC, T_FINAL, T_ABSTRACT, T_READONLY]) === true) {
                continue;
            }

            if ($phpToken->is([T_CLASS, T_INTERFACE, T_ENUM, T_TRAIT]) === true) {
                return ['class', true];
            }

            return [$phpToken->is(T_FUNCTION) === true ? 'method' : 'other', $public];
        }

        return ['other', $public];
    }

    /**
     * @param list<PhpToken> $tokens
     */
    private static function closingBracket(array $tokens, int $at): int
    {
        for ($depth = 0; isset($tokens[$at]) === true; ++$at) {
            $depth += match ($tokens[$at]->text) {
                '[', '#[' => 1,
                ']' => -1,
                default => 0,
            };

            if ($depth === 0) {
                return $at;
            }
        }

        return $at;
    }

    /**
     * @return array{int, int} the lines of prose before the first tag, and the @param tags with a description
     */
    private static function read(string $docblock): array
    {
        $prose = 0;
        $described = 0;
        $inTags = false;

        foreach (explode("\n", $docblock) as $line) {
            $text = trim((string) preg_replace('~^\s*(/\*\*|\*/|\*)~', '', rtrim($line)));
            $text = trim((string) preg_replace('~\*/$~', '', $text));
            $inTags = $inTags === true || str_starts_with($text, '@');

            if ($inTags === false && $text !== '') {
                ++$prose;
            }

            if (preg_match('~^@param\s+[^$]*\$\w+\s+\S~', $text) === 1) {
                ++$described;
            }
        }

        return [$prose, $described];
    }

    /**
     * @return list<string> as "line N: what"
     */
    private static function branchesIn(string $code): array
    {
        $tokens = array_values(array_filter(PhpToken::tokenize($code), static function (PhpToken $phpToken): bool {
            return $phpToken->isIgnorable() === false;
        }));
        $offences = [];

        foreach ($tokens as $index => $phpToken) {
            if ($phpToken->is([T_ELSE, T_ELSEIF]) === true) {
                $offences[] = \sprintf('line %d: %s', $phpToken->line, strtolower($phpToken->text));
            }

            $opens = ($tokens[$index + 1] ?? null)?->text === '(' && ($tokens[$index + 2] ?? null)?->text === '!';

            if ($phpToken->is(T_IF) === true && $opens === true) {
                $offences[] = \sprintf('line %d: if (!', $phpToken->line);
            }
        }

        return $offences;
    }

    /**
     * @return array<string, string> the path in the package => the real path
     */
    private static function filesUnder(string $directory): array
    {
        $root = \dirname(__DIR__, 2);
        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $files[substr($file->getPathname(), \strlen($root) + 1)] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * @return array<string, string> the path under examples/help => the real path
     */
    private static function examples(string $directory): array
    {
        $files = [];

        $paths = glob($directory . '/*/php.php');

        foreach ($paths === false ? [] : $paths as $path) {
            $files['examples/help/' . basename(\dirname($path)) . '/php.php'] = $path;
        }

        return $files;
    }

    /**
     * @return list<string> as "line N: what"
     */
    private static function offencesIn(string $code): array
    {
        $offences = [];

        foreach (self::commentBlocks($code) as [$line, $lines]) {
            if ($lines > self::MAX_COMMENT_LINES) {
                $offences[] = \sprintf('line %d: a comment of %d lines', $line, $lines);
            }
        }

        foreach (explode("\n", $code) as $index => $text) {
            if (preg_match(self::REFERENCE, $text) === 1) {
                $offences[] = \sprintf('line %d: a reference to docs, findings or decisions', $index + 1);
            }

            if (preg_match(self::HISTORY, $text) === 1) {
                $offences[] = \sprintf('line %d: history', $index + 1);
            }
        }

        return $offences;
    }

    /**
     * Each comment with the number of its lines of prose (tags of a docblock are not prose); line comments
     * that follow each other with nothing but white space between them are one comment.
     *
     * @return list<array{int, int}> the first line and the number of lines
     */
    private static function commentBlocks(string $code): array
    {
        $blocks = [];
        $first = null;
        $lines = 0;
        $previousEnd = null;

        foreach (PhpToken::tokenize($code) as $phpToken) {
            if ($phpToken->is(T_WHITESPACE) && substr_count($phpToken->text, "\n") <= 1) {
                continue;
            }

            $isLineComment = $phpToken->is(T_COMMENT) && (str_starts_with($phpToken->text, '//') || str_starts_with($phpToken->text, '#'));
            $continues = $isLineComment && $previousEnd === $phpToken->line - 1;

            if ($first !== null && !$continues) {
                $blocks[] = [$first, $lines];
                $first = null;
            }

            if ($phpToken->is([T_COMMENT, T_DOC_COMMENT])) {
                $first ??= $phpToken->line;
                $lines = ($continues ? $lines : 0) + self::sayingLines($phpToken->text);
            }

            $previousEnd = $isLineComment ? $phpToken->line : null;
        }

        if ($first !== null) {
            $blocks[] = [$first, $lines];
        }

        return $blocks;
    }

    private static function sayingLines(string $comment): int
    {
        $count = 0;

        foreach (explode("\n", $comment) as $line) {
            $text = trim((string) preg_replace('~^\s*(/\*\*?|\*/|\*|//|#)~', '', rtrim($line)));
            $text = trim((string) preg_replace('~\*/$~', '', $text));

            if ($text !== '' && !str_starts_with($text, '@')) {
                ++$count;
            }
        }

        return $count;
    }
}
