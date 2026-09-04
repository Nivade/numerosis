<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Support;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;

/**
 * Which concrete class the package uses for each of its models, and the
 * model↔factory name mapping that follows from it.
 *
 * `Numerosis::model()`, `::factoryNameFor()`, `::modelNameFor()` and
 * `::resetModelCache()` still exist and delegate here. They are the idiom this
 * codebase and every host's `config/numerosis.php` already use; moving the
 * implementation is the point, renaming ~200 call sites is not.
 */
final class ModelResolver
{
    /**
     * Memoized {@see self::resolve()} results.
     *
     * @var array<class-string<Model>, class-string<Model>>
     */
    private static array $cache = [];

    /**
     * Resolve which class the package should use for one of its models, so
     * that your own subclass is used everywhere the package queries it.
     *
     * Resolution order:
     *
     * 1. `config('numerosis.models.{$model}')`, if set.
     * 2. The same class name under your app namespace (`App\Models\Central\Tenant`
     *    for `Nvade\Numerosis\Models\Central\Tenant`), if it exists and extends
     *    the package model — so a conventionally-named subclass needs no config
     *    at all. An unrelated class of that name is ignored.
     * 3. The package's own class.
     *
     * Every model this covers is concrete, so overriding is optional. Results
     * are memoized for the lifetime of the process.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @return class-string<TModel>
     */
    public static function resolve(string $model): string
    {
        if (isset(self::$cache[$model])) {
            /** @var class-string<TModel> */
            return self::$cache[$model];
        }

        $override = Config::get("numerosis.models.{$model}");

        if (is_string($override) && $override !== '') {
            /** @var class-string<TModel> $override */
            return self::$cache[$model] = $override;
        }

        $hostModel = self::hostNamespaced(self::suffixAfter($model, '\\Models\\'));

        if (class_exists($hostModel) && is_subclass_of($hostModel, $model)) {
            /** @var class-string<TModel> $hostModel */
            return self::$cache[$model] = $hostModel;
        }

        return self::$cache[$model] = $model;
    }

    /**
     * Clear {@see self::resolve()}'s memoization. Runs on every boot, since
     * the cache is static and would otherwise outlive an application instance
     * under Octane or in tests.
     */
    public static function flush(): void
    {
        self::$cache = [];
    }

    /**
     * The factory that builds a given model.
     *
     * Replaces Laravel's own guesser, because factories ship from this package
     * even when the model is a subclass in your own app namespace.
     *
     * This replaces Laravel's *global* resolver, so it also answers for models
     * of your own that have nothing to do with this package: any class under a
     * `\Models\` namespace — `App\Models\User` included — resolves to
     * `Nvade\Numerosis\Database\Factories\<suffix>Factory`. The failure that
     * causes is not a wrong class but wrong *fields*: {@see self::modelFor()}
     * still instantiates your model, so `App\Models\User::factory()` builds
     * your model from the package factory's definition, silently missing
     * whatever columns your own migrations added. Escape it per model with
     * Laravel's own attribute, which `HasFactory::newFactory()` consults
     * before any global resolver:
     *
     * ```php
     * #[UseFactory(\Database\Factories\UserFactory::class)]
     * class User extends Authenticatable {}
     * ```
     *
     * @param  class-string<Model>  $modelName
     * @return class-string<Factory<Model>>
     */
    public static function factoryFor(string $modelName): string
    {
        /** @var class-string<Factory<Model>> $factoryName */
        $factoryName = 'Nvade\\Numerosis\\Database\\Factories\\'
            .self::suffixAfter($modelName, '\\Models\\')
            .'Factory';

        return $factoryName;
    }

    /**
     * The reverse of {@see self::factoryFor()}. Prefers a subclass in your own
     * app namespace when one exists, so factories build the model you actually
     * extended, and falls back to the package's own class.
     *
     * @param  class-string<Factory<Model>>  $factoryName
     * @return class-string<Model>
     */
    public static function modelFor(string $factoryName): string
    {
        $suffix = self::suffixAfter($factoryName, '\\Database\\Factories\\');
        $suffix = preg_replace('/Factory$/', '', $suffix) ?? $suffix;

        $hostModel = self::hostNamespaced($suffix);

        if (class_exists($hostModel)) {
            /** @var class-string<Model> $hostModel */
            return $hostModel;
        }

        /** @var class-string<Model> $packageModel */
        $packageModel = 'Nvade\\Numerosis\\Models\\'.$suffix;

        return $packageModel;
    }

    /**
     * Everything after `$marker`, keeping any sub-namespace (`Central\Tenant`,
     * not `Tenant`) so `Models\Central\Tenant` and a hypothetical
     * `Models\Tenant\Tenant` cannot collapse onto one name.
     */
    private static function suffixAfter(string $class, string $marker): string
    {
        $position = strpos($class, $marker);

        return $position === false
            ? class_basename($class)
            : substr($class, $position + strlen($marker));
    }

    /** The same suffix under the host application's own namespace. */
    private static function hostNamespaced(string $suffix): string
    {
        return rtrim((string) app()->getNamespace(), '\\').'\\Models\\'.$suffix;
    }
}
