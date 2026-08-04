<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveReturnTagIncompatibleWithNativeTypeRector;
use RectorLaravel\Rector\FuncCall\RemoveDumpDataDeadCodeRector;
use RectorLaravel\Set\LaravelSetList;
use RectorLaravel\Set\LaravelSetProvider;

return RectorConfig::configure()
    ->withSetProviders(LaravelSetProvider::class)
    ->withComposerBased(laravel: true)
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/tests',
    ])
    ->withSkip([
        __DIR__.'/database/migrations',
        __DIR__.'/app-modules/*/database/migrations',
        // Strips @return PlanMetadata-style aliases that carry real generic
        // info beyond the native `array` return type — see
        // .claude/rules/static-analysis.md.
        RemoveReturnTagIncompatibleWithNativeTypeRector::class,
    ])
    ->withPhpSets()
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        earlyReturn: true,
    )
    ->withImportNames()
    ->withCache(cacheDirectory: __DIR__.'/storage/rector')
    ->withParallel()
    ->withSets([
        LaravelSetList::LARAVEL_ARRAYACCESS_TO_METHOD_CALL,
        LaravelSetList::LARAVEL_ARRAY_STR_FUNCTION_TO_STATIC_CALL,
        LaravelSetList::LARAVEL_CODE_QUALITY,
        LaravelSetList::LARAVEL_COLLECTION,
        LaravelSetList::LARAVEL_CONTAINER_STRING_TO_FULLY_QUALIFIED_NAME,
        LaravelSetList::LARAVEL_TYPE_DECLARATIONS,
        LaravelSetList::LARAVEL_TESTING,
        LaravelSetList::LARAVEL_130,
        LaravelSetList::LARAVEL_IF_HELPERS,
        LaravelSetList::LARAVEL_FACTORIES,
        LaravelSetList::LARAVEL_FACADE_ALIASES_TO_FULL_NAMES,

    ])->withConfiguredRule(
        RemoveDumpDataDeadCodeRector::class, ['dd', 'dump', 'var_dump']
    );
