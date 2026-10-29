<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit;

use PhpToken;
use PHPUnit\Framework\TestCase;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionUnionType;
use Slen\Sdk\SlenConfig;
use Slen\Sdk\Tests\Unit\Support\SourceTree;
use Throwable;

/**
 * The rules of the house the source is held to by reflection and by its tokens.
 */
final class HouseRulesTest extends TestCase
{
    private const int MOST_ARGUMENTS = 3;

    public function testNoMethodTakesMoreThanThreeArgumentsAndNoClassMoreThanThreeDependencies(): void
    {
        $sourceTree = new SourceTree();
        $classes = $sourceTree->classes();
        $offences = [];

        self::assertGreaterThan(100, \count($classes), 'the test has to be reading the source');

        foreach ($classes as $reflectionClass) {
            foreach ($sourceTree->ownMethods($reflectionClass) as $reflectionMethod) {
                if ($reflectionMethod->getNumberOfParameters() <= self::MOST_ARGUMENTS || self::mayTakeMore($reflectionMethod) === true) {
                    continue;
                }

                $offences[] = $reflectionClass->getName() . '::' . $reflectionMethod->getName() . ' takes ' . $reflectionMethod->getNumberOfParameters();
            }
        }

        self::assertSame([], $offences, 'More than three travel in a DTO; only constructors of DTOs, options, exceptions and SlenConfig take more.');
    }

    public function testEveryMethodAnswersOneTypeOrNull(): void
    {
        $offences = [];

        foreach ((new SourceTree())->classes() as $reflectionClass) {
            foreach ((new SourceTree())->ownMethods($reflectionClass) as $reflectionMethod) {
                $returns = $reflectionMethod->getReturnType();

                if ($returns instanceof ReflectionUnionType || $returns instanceof ReflectionIntersectionType) {
                    $offences[] = $reflectionClass->getName() . '::' . $reflectionMethod->getName() . ': ' . $returns;
                }
            }
        }

        self::assertSame([], $offences, 'One type, or that type or null; several outcomes travel in a DTO.');
    }

    public function testASpanIsOpenedOnlyByAFactory(): void
    {
        $offences = [];

        foreach ((new SourceTree())->files() as $relative => $code) {
            if (str_contains($relative, '/Factories/') === true) {
                continue;
            }

            foreach (self::linesOfNew($code, 'SpanScope') as $line) {
                $offences[] = $relative . ':' . $line;
            }
        }

        self::assertSame([], $offences, 'A span is opened through the factory of its module.');
    }

    public function testTheScannerFindsANewOfTheClassItIsAsked(): void
    {
        $code = implode("\n", [
            '<?php',
            '$a = new SpanScope($r, $s);',
            '// new SpanScope',
            '$b = new \Slen\Sdk\Shared\Observability\SpanScope($r, $s);',
            '$c = new SpanScopeFactory();',
        ]);

        self::assertSame([2, 4], self::linesOfNew($code, 'SpanScope'));
    }

    private static function mayTakeMore(ReflectionMethod $reflectionMethod): bool
    {
        $class = $reflectionMethod->getDeclaringClass();

        return $reflectionMethod->isConstructor()
            && (
                str_ends_with($class->getName(), 'DTO')
                || str_ends_with($class->getName(), 'Options')
                || $class->implementsInterface(Throwable::class)
                || $class->getName() === SlenConfig::class
            );
    }

    /**
     * @return list<int> the lines where the code makes a new instance of $class
     */
    private static function linesOfNew(string $code, string $class): array
    {
        $tokens = array_values(array_filter(PhpToken::tokenize($code), static function (PhpToken $phpToken): bool {
            return $phpToken->isIgnorable() === false;
        }));
        $lines = [];

        foreach ($tokens as $index => $phpToken) {
            $next = $tokens[$index + 1] ?? null;

            if ($phpToken->is(T_NEW) === false || $next === null) {
                continue;
            }

            $name = ltrim($next->text, '\\');

            if ($name === $class || str_ends_with($name, '\\' . $class)) {
                $lines[] = $phpToken->line;
            }
        }

        return $lines;
    }
}
