<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests;

use FilesystemIterator;
use PhpToken;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * `composer.json` says `php: ^8.3`, and this suite cannot run there — it is
 * written in 8.4 syntax. So what ships (`src/`, `config/`) is read here, on
 * whatever PHP the suite runs on, for the newer syntax that has actually found
 * its way in: a file using it is a parse error on 8.3.
 *
 * This is the early warning, not the proof. The proof is `php -l` on a real
 * 8.3 over the same files (G-P3): only the 8.3 parser knows everything 8.3
 * cannot parse. A construct that check catches and this test does not gets a
 * rule here.
 */
class SourceFloorTest extends TestCase
{
    private const SHIPPED = [__DIR__.'/../src', __DIR__.'/../config', __DIR__.'/../bin'];

    public function testWhatShipsUsesNoSyntaxNewerThanTheDeclaredFloor(): void
    {
        $files = self::shippedFiles();
        $offences = [];

        foreach ($files as $file) {
            foreach (self::newerSyntaxIn((string) file_get_contents($file)) as $offence) {
                $offences[] = sprintf('%s: %s', substr($file, strlen(dirname(__DIR__)) + 1), $offence);
            }
        }

        self::assertGreaterThan(40, count($files), 'the test has to be reading the package');
        self::assertSame([], $offences, "PHP 8.3 cannot parse:\n".implode("\n", $offences));
    }

    public function testTheFloorIsStillTheOneThisTestHolds(): void
    {
        /** @var array{require: array<string, string>} $manifest */
        $manifest = json_decode((string) file_get_contents(__DIR__.'/../composer.json'), true, flags: JSON_THROW_ON_ERROR);

        // Raise the floor and the rules below are checking for the wrong
        // version: change them (and the `php-floor` job) with it.
        self::assertSame('^8.3', $manifest['require']['php']);
    }

    // The reader is held to what it is for: each construct newer than 8.3 is
    // named, and the 8.3 spellings next to them are not.
    public function testTheReaderTellsNewerSyntaxFromWhatTheFloorParses(): void
    {
        $newer = [
            'a member read off `new` without parentheses (8.4)' => 'class A { function f() { return new B()->c(); } }',
            'a static member read off `new` without parentheses (8.4)' => 'class A { function f() { return new B()::C; } }',
            'asymmetric visibility (8.4)' => 'class A { public private(set) string $name = ""; }',
            'a property hook (8.4)' => 'class A { public string $name { get => "a"; } }',
        ];

        foreach ($newer as $what => $code) {
            self::assertNotSame([], self::newerSyntaxIn('<?php '.$code), $what.' was not seen');
        }

        $parses = [
            'an untyped constant' => 'class A { private const SETTLED = []; public const NAME = "a", OTHER = "b"; }',
            'a constant named like a type' => 'class A { const array = 1; const string = "s"; }',
            'a global constant' => 'const TOP = 1;',
            'an imported constant' => 'use const Vendor\\NAME; class A { }',
            '`new` in parentheses' => 'class A { function f() { return (new B())->c(); } }',
            '`new` as an argument' => 'class A { function f() { return g(new B())->c(); } }',
            'an anonymous class' => 'class A { function f() { return new class () { }; } }',
            'a static method call by variable' => 'class A { function f($n) { return B::$n() . B::{"m"}(); } }',
            'a readonly promoted property' => 'final class A { public function __construct(public readonly ?string $name = null) { } }',
            'a match and an enum' => 'enum E: string { case A = "a"; function f() { return match (true) { default => 1 }; } }',
            'a typed class constant (8.3)' => 'class A { private const array SETTLED = []; public const int|string ID = 1; }',
            'a dynamic class constant fetch (8.3)' => 'class A { function f($n) { return B::{$n}; } }',
            'a readonly anonymous class (8.3)' => 'class A { function f() { return new readonly class {}; } }',
        ];

        foreach ($parses as $what => $code) {
            self::assertSame([], self::newerSyntaxIn('<?php '.$code), $what.' was taken for newer syntax');
        }
    }

    /**
     * @return list<string>
     */
    private static function shippedFiles(): array
    {
        $files = [];

        foreach (self::SHIPPED as $directory) {
            $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

            foreach ($walk as $file) {
                if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                    $files[] = (string) $file->getRealPath();
                }
            }
        }

        sort($files);

        return $files;
    }

    /**
     * What in the code PHP 8.3 would not parse, as `line N: what`.
     *
     * @return list<string>
     */
    private static function newerSyntaxIn(string $code): array
    {
        $tokens = array_values(array_filter(
            PhpToken::tokenize($code),
            static function (PhpToken $token): bool {
                return $token->isIgnorable() === false;
            },
        ));
        $found = [];

        foreach ($tokens as $at => $token) {
            $what = match (true) {
                $token->is(T_NEW) => self::newWithoutParentheses($tokens, $at),
                $token->is(T_VARIABLE) => self::textAt($tokens, $at + 1) === '{' && self::isProperty($tokens, $at)
                    ? 'a property hook (PHP 8.4)'
                    : null,
                str_ends_with((string) $token->getTokenName(), '_SET') => 'asymmetric visibility (PHP 8.4)',
                default => null,
            };

            if ($what !== null) {
                $found[] = sprintf('line %d: %s', $token->line, $what);
            }
        }

        return $found;
    }


    /**
     * `new B()->c()`: the arguments of `new` close, and a member is read off
     * the result at once. On 8.3 that needs `(new B())->c()` — there the
     * token after the arguments is the closing parenthesis, not the arrow.
     *
     * @param list<PhpToken> $tokens
     */
    private static function newWithoutParentheses(array $tokens, int $at): ?string
    {
        $next = $at + 1;

        if (isset($tokens[$next]) && $tokens[$next]->is([T_READONLY, T_CLASS])) {
            return null;
        }

        // The class: a name, `static`, a variable.
        while (isset($tokens[$next]) && $tokens[$next]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE, T_STATIC, T_VARIABLE])) {
            $next++;
        }

        if (self::textAt($tokens, $next) !== '(') {
            return null;
        }

        $after = self::afterClosing($tokens, $next, '(', ')');

        return isset($tokens[$after]) && $tokens[$after]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON])
            ? 'a member read off `new` without parentheses (PHP 8.4)'
            : null;
    }


    /**
     * A variable followed by `{` is a property with hooks when it is a
     * declaration: what stands before it is a type or a modifier, not an
     * expression.
     *
     * @param list<PhpToken> $tokens
     */
    private static function isProperty(array $tokens, int $at): bool
    {
        return $at > 0 && $tokens[$at - 1]->is([
            T_PUBLIC, T_PROTECTED, T_PRIVATE, T_READONLY, T_STATIC, T_VAR, T_STRING, T_ARRAY, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED,
        ]);
    }

    /**
     * The index of the token after the bracket that closes the one at `$open`.
     *
     * @param list<PhpToken> $tokens
     */
    private static function afterClosing(array $tokens, int $open, string $opens, string $closes): int
    {
        $depth = 0;

        for ($at = $open; isset($tokens[$at]); $at++) {
            if ($tokens[$at]->text === $opens) {
                $depth++;
            }

            if ($tokens[$at]->text === $closes && --$depth === 0) {
                return $at + 1;
            }
        }

        return $at;
    }

    /**
     * @param list<PhpToken> $tokens
     */
    private static function textAt(array $tokens, int $at): ?string
    {
        return isset($tokens[$at]) ? $tokens[$at]->text : null;
    }
}
