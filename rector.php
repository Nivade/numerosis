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
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withSkip([
        // Guards a path withPaths() does not scan yet: whoever adds `database`
        // there must not have rector rewriting applied migrations.
        __DIR__.'/database/migrations',
        // Strips @return PlanMetadata-style aliases that carry real generic
        // info beyond the native `array` return type — see
        // .ai/rules/static-analysis.md.
        RemoveReturnTagIncompatibleWithNativeTypeRector::class,
        // Domains.php reads raw superglobals deliberately, called from inside
        // config/numerosis.php while the config repository is still being
        // built — before facades are registered. A rector pass
        // (ServerVariableToRequestFacadeRector) once rewrote the superglobal
        // read to Request::server(...) here and caused a real boot-time
        // crash-loop (facade root not set). Skip the whole file rather than
        // one rule at a time — see .ai/rules/package-host-bootstrap.md's
        // first bullet.
        __DIR__.'/src/Support/Domains.php',
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
