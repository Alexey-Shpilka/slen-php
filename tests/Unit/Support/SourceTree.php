<?php

declare(strict_types=1);

namespace Slen\Sdk\Tests\Unit\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use SplFileInfo;

/**
 * The files and the classes of src/, for the tests that hold rules over all of them.
 */
final readonly class SourceTree
{
    private const string SOURCE = __DIR__ . '/../../../src';

    /**
     * @return array<string, string> the path under src/ => the code
     */
    public function files(): array
    {
        $root = (string) realpath(self::SOURCE);
        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo === false || $file->getExtension() !== 'php') {
                continue;
            }

            $files[substr($file->getPathname(), \strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
        }

        ksort($files);

        return $files;
    }

    /**
     * @return list<ReflectionClass<object>>
     */
    public function classes(): array
    {
        $classes = [];

        foreach (array_keys($this->files()) as $relative) {
            /** @var class-string $name */
            $name = 'Slen\Sdk\\' . str_replace('/', '\\', substr($relative, 0, -4));
            $classes[] = new ReflectionClass($name);
        }

        return $classes;
    }

    /**
     * @param ReflectionClass<object> $reflectionClass
     *
     * @return list<ReflectionMethod> the methods the class declares itself, not those of an enum or a parent
     */
    public function ownMethods(ReflectionClass $reflectionClass): array
    {
        return array_values(array_filter(
            $reflectionClass->getMethods(),
            static function (ReflectionMethod $reflectionMethod) use ($reflectionClass): bool {
                return $reflectionMethod->isUserDefined() && $reflectionMethod->getDeclaringClass()->getName() === $reflectionClass->getName();
            },
        ));
    }
}
