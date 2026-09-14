<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\ClassMethod\LocallyCalledStaticMethodToNonStaticRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveReturnTagIncompatibleWithNativeTypeRector;
use Rector\DeadCode\Rector\Node\RemoveNonExistingVarAnnotationRector;
use Rector\Php81\Rector\Property\ReadOnlyPropertyRector;
use Rector\Transform\Rector\ArrayDimFetch\ArrayDimFetchToMethodCallRector;
use RectorLaravel\Rector\ArrayDimFetch\EnvVariableToEnvHelperRector;
use RectorLaravel\Rector\Class_\AddHasFactoryToModelsRector;
use RectorLaravel\Rector\Class_\BackoffPropertyToBackoffAttributeRector;
use RectorLaravel\Rector\Class_\TriesPropertyToTriesAttributeRector;
use RectorLaravel\Rector\ClassMethod\AddGenericReturnTypeToRelationsRector;
use RectorLaravel\Rector\FuncCall\RemoveDumpDataDeadCodeRector;
use RectorLaravel\Rector\FuncCall\ThrowIfAndThrowUnlessExceptionsToUseClassStringRector;
use RectorLaravel\Set\LaravelSetList;

return RectorConfig::configure()
    ->withComposerBased(laravel: true)
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
        __DIR__.'/packages/ui/src',
        __DIR__.'/packages/ui/tests',
    ])
    // withSkipPath() asserts the path exists; withSkip() would go silently
    // vacuous the next time one of these files moves.
    // Rector must never rewrite an already-applied migration.
    ->withSkipPath(__DIR__.'/database/migrations')
    // Reads raw superglobals deliberately, from config/numerosis.php before
    // facades are registered; ServerVariableToRequestFacadeRector once rewrote
    // that to Request::server() and crash-looped every worker.
    ->withSkipPath(__DIR__.'/src/Boot/Domains.php')
    ->withSkip([
        // Strips @return PlanMetadata-style aliases that carry real generic
        // info beyond the native `array` return type.
        RemoveReturnTagIncompatibleWithNativeTypeRector::class,
        // Emits `unset(Env::get('APP_URL'))`, which is not valid PHP: unset()
        // requires a variable.
        EnvVariableToEnvHelperRector::class,
        // Static private helpers are deliberate here, and the rule cannot see
        // a PHPUnit data provider's static call site.
        LocallyCalledStaticMethodToNonStaticRector::class,
        // Rewrites throw_unless()'s exception-instance form to a class-string
        // + message args, dropping the `previous:` named argument.
        ThrowIfAndThrowUnlessExceptionsToUseClassStringRector::class,
        // Strips @var annotations that are the only thing narrowing a generic
        // template, e.g. ModelResolver::resolve()'s class-string<TModel>.
        RemoveNonExistingVarAnnotationRector::class,
        // Adds HasFactory to models that have no factory, ungenericised.
        AddHasFactoryToModelsRector::class,
        // A readonly Collection narrows to Collection<*NEVER*,*NEVER*>, which
        // makes every later isNotEmpty() read as always-false.
        ReadOnlyPropertyRector::class,
        // Deletes the $tries/$backoff declarations that RunProvisioningStep
        // assigns at runtime for ControlsItsOwnRetries steps.
        TriesPropertyToTriesAttributeRector::class,
        BackoffPropertyToBackoffAttributeRector::class,
        // Infers the pivot template parameter from the wrong model.
        AddGenericReturnTypeToRelationsRector::class,
        // Turns `$app['env'] = 'local'` into bind(), losing the closure wrap
        // Container::offsetSet() applies, so the value resolves as a class.
        ArrayDimFetchToMethodCallRector::class,
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
        LaravelSetList::LARAVEL_IF_HELPERS,
        LaravelSetList::LARAVEL_FACTORIES,
        LaravelSetList::LARAVEL_FACADE_ALIASES_TO_FULL_NAMES,
    ])->withConfiguredRule(
        RemoveDumpDataDeadCodeRector::class, ['dd', 'dump', 'var_dump']
    );
