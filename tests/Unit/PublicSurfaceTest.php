<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use Slen\Sdk\Exceptions\SlenException;
use Slen\Sdk\Modules\Customers\CustomersFacade;
use Slen\Sdk\Modules\Executions\ExecutionsFacade;
use Slen\Sdk\Modules\Operations\OperationsFacade;
use Slen\Sdk\Modules\Webhooks\WebhooksFacade;
use Slen\Sdk\SlenConfig;
use Slen\Sdk\SlenSdk;
use Slen\Sdk\Tests\Paths;
use SplFileInfo;

/**
 * The boundary of the public surface is a namespace: what a caller may name lives outside `Shared`, and every
 * class under `Shared` or in a module's factories, tasks, resources, actions or services is `@internal`.
 */
final class PublicSurfaceTest extends TestCase
{
    private const string SOURCE = __DIR__ . '/../../src';

    private const string SLICE = Paths::PACKAGE . '/help-slice.json';

    private const string INTERNAL_PLACE = '~^Slen\\\\Sdk\\\\(Shared\\\\|Modules\\\\\w+\\\\(Factories|Tasks|Resources|Actions|Services)\\\\)~';

    private const array PUBLIC_PLACES = [
        '~^Slen\\\\Sdk\\\\\w+$~',
        '~^Slen\\\\Sdk\\\\(Exceptions|Data|Observability|Testing|Laravel)\\\\\w+$~',
        '~^Slen\\\\Sdk\\\\Modules\\\\\w+\\\\\w+Facade$~',
        '~^Slen\\\\Sdk\\\\Modules\\\\\w+\\\\(Data|Enums|Options)\\\\\w+$~',
    ];

    public function testEveryClassOfAnInternalPlaceIsInternal(): void
    {
        $inside = 0;
        $public = [];

        foreach (self::classes() as $class) {
            if (preg_match(self::INTERNAL_PLACE, $class) !== 1) {
                continue;
            }

            ++$inside;

            if (self::isInternal($class) === false) {
                $public[] = $class;
            }
        }

        self::assertGreaterThan(30, $inside, 'the test has to be reading the internal classes');
        self::assertSame([], $public, 'Mark these @internal, or move them out of Shared and the inner layers of a module.');
    }

    public function testEveryPublicClassLivesInAPublicNamespace(): void
    {
        $public = [];
        $astray = [];

        foreach (self::classes() as $class) {
            if (self::isInternal($class) === false) {
                $public[] = $class;
            }
        }

        foreach ($public as $class) {
            if (self::inAPublicPlace($class) === false) {
                $astray[] = $class;
            }
        }

        self::assertContains(SlenSdk::class, $public);
        self::assertContains(SlenException::class, $public);
        self::assertSame([], $astray, 'A public class lives at the root, in Exceptions, Data, Observability, Testing, Laravel, or a module.');
    }

    public function testACallNamesItsSubjectAndTakesTheRestAsOneOptionalOptions(): void
    {
        $problems = [];

        foreach (self::callSurface() as $method) {
            $parameters = $method->getParameters();
            $name = $method->class . '::' . $method->getName();

            if (\count($parameters) > 3) {
                $problems[] = $name . ' takes more than three arguments';
            }

            if (self::isCall($method) === false) {
                continue;
            }

            $last = $parameters[\count($parameters) - 1] ?? null;
            $type = $last?->getType();

            if ($last === null || self::isOptionalOptions($type) === false) {
                $problems[] = $name . ' does not end with an optional …Options';

                continue;
            }

            if ($last->isDefaultValueAvailable() === false || $last->getDefaultValue() !== null) {
                $problems[] = $name . ' does not default its options to null';
            }
        }

        self::assertSame([], $problems, implode("\n", $problems));
    }

    public function testTheClientOfTheSliceIsTheEntryPointAndItsConfig(): void
    {
        /** @var array{client: array{class: string, options: string}} $slice */
        $slice = json_decode((string) file_get_contents(self::SLICE), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(['class' => SlenSdk::class, 'options' => SlenConfig::class], $slice['client']);
    }

    /**
     * @return list<string> every class, interface, trait and enum of src/, by the PSR-4 root
     */
    private static function classes(): array
    {
        $root = (string) realpath(self::SOURCE);
        $classes = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo === false || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), \strlen($root) + 1, -4);
            $classes[] = 'Slen\Sdk\\' . str_replace('/', '\\', $relative);
        }

        sort($classes);

        return $classes;
    }

    /**
     * @return list<ReflectionMethod> the public methods of SlenSdk and of the facades it hands out, and their statics
     */
    private static function callSurface(): array
    {
        $methods = [];

        foreach ([SlenSdk::class, OperationsFacade::class, ExecutionsFacade::class, CustomersFacade::class, WebhooksFacade::class] as $class) {
            foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isConstructor() === false && str_starts_with($method->getName(), '__') === false) {
                    $methods[] = $method;
                }
            }
        }

        return $methods;
    }

    /**
     * As the contract test and the help slice classify them: every method of a facade, and a method of SlenSdk that
     * answers a DTO; a method that hands out a module, a client or a receiver is not a call.
     */
    private static function isCall(ReflectionMethod $method): bool
    {
        $returns = $method->getReturnType();
        $answersADto = $returns instanceof ReflectionNamedType && str_ends_with($returns->getName(), 'DTO');

        return $method->isStatic() === false && ($method->class !== SlenSdk::class || $answersADto);
    }

    private static function isOptionalOptions(?ReflectionType $type): bool
    {
        return $type instanceof ReflectionNamedType && $type->allowsNull() && str_ends_with($type->getName(), 'Options');
    }

    private static function isInternal(string $class): bool
    {
        /** @var class-string $class */
        return str_contains((string) (new ReflectionClass($class))->getDocComment(), '@internal');
    }

    private static function inAPublicPlace(string $class): bool
    {
        foreach (self::PUBLIC_PLACES as $place) {
            if (preg_match($place, $class) === 1) {
                return true;
            }
        }

        return false;
    }
}
