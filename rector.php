<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\ClassMethod\LocallyCalledStaticMethodToNonStaticRector;
use Rector\CodeQuality\Rector\Identical\SimplifyBoolIdenticalTrueRector;
use Rector\CodingStyle\Rector\Catch_\CatchExceptionNameMatchingTypeRector;
use Rector\CodingStyle\Rector\ClassMethod\MakeInheritedMethodVisibilitySameAsParentRector;
use Rector\Config\RectorConfig;
use Rector\Php74\Rector\Closure\ClosureToArrowFunctionRector;
use Rector\PHPUnit\CodeQuality\Rector\Class_\PreferPHPUnitThisCallRector;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src', __DIR__ . '/tests/Unit'])
    ->withPhpSets(php83: true)
    ->withImportNames(importDocBlockNames: false, importShortClasses: false)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        privatization: true,
        instanceOf: true,
        earlyReturn: true,
        phpunitCodeQuality: true,
    )
    ->withAttributesSets(phpunit: true)
    ->withSkip([
        PreferPHPUnitThisCallRector::class,
        LocallyCalledStaticMethodToNonStaticRector::class,
        // A test opens a protected method by overriding it as public.
        MakeInheritedMethodVisibilitySameAsParentRector::class => [__DIR__ . '/tests/Unit/Support'],
        CatchExceptionNameMatchingTypeRector::class,
        // The house rules: a boolean is compared explicitly, and a closure is never an arrow function.
        SimplifyBoolIdenticalTrueRector::class,
        ClosureToArrowFunctionRector::class,
    ]);
