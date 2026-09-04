<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Support;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;

/**
 * Which concrete class the package uses for each of its models, and the
 * model-to-factory name mapping that follows from it.
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
     * Resolve which class the package uses for one of its models: an explicit
     * `numerosis.models.{$model}` entry, then a subclass of it at the
     * conventional `App\Models\<suffix>` path, then the package's own class. A
     * class at that path that does not extend the package model is ignored.
     * Results are memoized per process.
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
     * Replaces Laravel's *global* factory-name resolver, since every factory
     * ships from this package even when the model is a subclass in your app
     * namespace, so any class under a `\Models\` namespace resolves to
     * `Nvade\Numerosis\Database\Factories\<suffix>Factory`. `#[UseFactory]` on
     * a model of your own short-circuits that.
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
     * Everything after `$marker`, keeping any sub-namespace so
     * `Models\Central\Tenant` and a hypothetical `Models\Tenant\Tenant` cannot
     * collapse onto one name.
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
