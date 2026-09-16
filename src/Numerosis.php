<?php

declare(strict_types=1);

namespace Nvade\Numerosis;

use Closure;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\ApplicationBuilder;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Boot\Assets;
use Nvade\Numerosis\Boot\ExceptionRegistrar;
use Nvade\Numerosis\Boot\MiddlewareRegistrar;
use Nvade\Numerosis\Boot\ModelResolver;
use Nvade\Numerosis\Models\Central;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Domain;
use Nvade\Numerosis\Models\Central\Invitation;
use Nvade\Numerosis\Models\Central\OwnershipNomination;
use Nvade\Numerosis\Models\Central\PaymentPlan;
use Nvade\Numerosis\Models\Central\SocialAccount;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Models\Central\TenantProvision;
use Nvade\Numerosis\Models\Tenant as TenantModels;
use Nvade\Numerosis\Routing\RouteLoader;

/**
 * The package's entry point for a host's `bootstrap/app.php`, and the front
 * door to every seam a host or satellite uses. Every method here delegates,
 * so that `Numerosis::` stays the one idiom a host needs.
 *
 * @see RouteLoader route groups
 * @see MiddlewareRegistrar aliases, groups and trust
 * @see ExceptionRegistrar report context and throttling
 * @see ModelResolver model and factory resolution
 * @see Assets front-end publishing
 */
class Numerosis
{
    /**
     * Whether a request is addressed to one of the central hostnames rather
     * than to a tenant subdomain. Also available as the
     * `request()->isCentralDomain()` macro; prefer this method where static
     * analysis matters, since macros are invisible to it.
     */
    public static function isCentralDomain(?Request $request = null): bool
    {
        $request ??= resolve(Request::class);

        return in_array($request->getHost(), Config::array('tenancy.central_domains'), true);
    }

    // The four below run while the ApplicationBuilder is still being built:
    // before RegisterFacades, and before LoadConfiguration has finished. Never
    // reach them through the Facades\Numerosis facade.

    /**
     * The greenfield convenience, reducing `bootstrap/app.php` to one line:
     *
     * ```php
     * return Numerosis::configure(basePath: dirname(__DIR__))->create();
     * ```
     *
     * Applies {@see self::routes()}, {@see self::middleware()} and
     * {@see self::exceptions()}. Write the `Application::configure()` chain
     * yourself to reach `health:` or `then:`; passing a callable `then:`
     * alongside `using:` discards this package's routing entirely.
     */
    public static function configure(
        ?string $basePath = null,
        ?string $commands = null,
        ?string $channels = null,
        string $apiPrefix = 'api',
    ): ApplicationBuilder {
        return Application::configure(basePath: $basePath)
            ->withRouting(
                using: fn () => self::routes(apiPrefix: $apiPrefix),
                commands: $commands,
                channels: $channels,
            )
            ->withMiddleware(self::middleware(...))
            ->withExceptions(self::exceptions(...));
    }

    /**
     * Route registration for `bootstrap/app.php`'s `withRouting(using: ...)`.
     * Central routes bind to each hostname in `tenancy.central_domains`
     * separately, because tenant identification never runs for those domains.
     * `withAuth: false` skips Fortify's route file in both groups, handing you
     * `login`, `register`, `logout` and `verification.verify`.
     *
     * ```php
     * ->withRouting(using: fn () => Numerosis::routes(withAuth: false))
     * ```
     *
     * @see self::registerRoutesUsing()
     */
    public static function routes(bool $withAuth = true, string $apiPrefix = 'api'): void
    {
        RouteLoader::load($withAuth, $apiPrefix);
    }

    /**
     * Middleware aliases, groups, and trust configuration for
     * `bootstrap/app.php`'s `withMiddleware()`. CSRF is left alone: pass
     * {@see self::csrfExceptions()} to `preventRequestForgery()` yourself,
     * so you can add your own exempt paths to the list.
     *
     * Call it first in the closure: anything you configure afterwards wins.
     */
    public static function middleware(Middleware $middleware): void
    {
        MiddlewareRegistrar::apply($middleware);
    }

    /**
     * Exception context and throttling for `bootstrap/app.php`'s
     * `withExceptions()`. Adds the current tenant, guard and user to every
     * report. {@see ExceptionRegistrar::apply()}
     */
    public static function exceptions(Exceptions $exceptions): void
    {
        ExceptionRegistrar::apply($exceptions);
    }

    // End of the boot-phase group.

    /** {@see RouteLoader::registered()} */
    public static function routesRegistered(): bool
    {
        return RouteLoader::registered();
    }

    /** {@see RouteLoader::authRoutesEnabled()} */
    public static function authRoutesEnabled(): bool
    {
        return RouteLoader::authRoutesEnabled();
    }

    /** {@see MiddlewareRegistrar::registered()} */
    public static function middlewareRegistered(): bool
    {
        return MiddlewareRegistrar::registered();
    }

    /** {@see MiddlewareRegistrar::resetForTesting()} */
    public static function resetMiddlewareRegisteredForTesting(): void
    {
        MiddlewareRegistrar::resetForTesting();
    }

    /**
     * {@see MiddlewareRegistrar::csrfExceptions()}
     *
     * @return list<string>
     */
    public static function csrfExceptions(): array
    {
        return MiddlewareRegistrar::csrfExceptions();
    }

    /**
     * {@see MiddlewareRegistrar::aliases()}
     *
     * @return array<string, string>
     */
    public static function middlewareAliases(): array
    {
        return MiddlewareRegistrar::aliases();
    }

    /**
     * {@see MiddlewareRegistrar::groups()}
     *
     * @return array<string, list<string>>
     */
    public static function middlewareGroups(): array
    {
        return MiddlewareRegistrar::groups();
    }

    /**
     * {@see MiddlewareRegistrar::groupAppends()}
     *
     * @return array<string, list<string>>
     */
    public static function middlewareGroupAppends(): array
    {
        return MiddlewareRegistrar::groupAppends();
    }

    /**
     * Registered as Laravel's factory-name resolver.
     *
     * Implementation, and the full caveat about it answering for your own
     * App\Models\* classes too: {@see ModelResolver::factoryFor()}.
     *
     * @param  class-string<Model>  $modelName
     * @return class-string<Factory<Model>>
     */
    public static function factoryNameFor(string $modelName): string
    {
        return ModelResolver::factoryFor($modelName);
    }

    /**
     * Registered as Laravel's model-name resolver.
     * {@see ModelResolver::modelFor()}.
     *
     * @template TModel of Model
     *
     * @param  class-string<Factory<TModel>>  $factoryName
     * @return class-string<TModel>
     */
    public static function modelNameFor(string $factoryName): string
    {
        return ModelResolver::modelFor($factoryName);
    }

    /**
     * Absolute path to the package's tenant migrations, for
     * `config('tenancy.migration_parameters')['--path']`. Point tenancy at
     * this path directly; publish the migrations only when you need to
     * customise one.
     */
    public static function tenantMigrationPath(): string
    {
        return __DIR__.'/../database/migrations/tenant';
    }

    /**
     * Every path tenancy should migrate. `HostConfig` appends these to
     * whatever `tenancy.migration_parameters['--path']` already holds,
     * alongside the host's own `database/migrations/tenant`.
     *
     * @return list<string>
     */
    public static function tenantMigrationPaths(): array
    {
        return [self::tenantMigrationPath()];
    }

    /**
     * The `numerosis-assets` publish group, as source => target directory.
     * {@see Assets::sourcePaths()}.
     *
     * @return array<string, string>
     */
    public static function assetSourcePaths(): array
    {
        return Assets::sourcePaths();
    }

    /**
     * The `<link>`/`<script>` tags for the package's non-panel CSS and JS.
     * {@see Assets::tags()}.
     */
    public static function assetTags(): Htmlable
    {
        return Assets::tags();
    }

    /**
     * The models a host may subclass, as package class => path relative to
     * both `stubs/Models` and the host's `app/Models`. The `numerosis-models`
     * publish group and `numerosis:install`'s override check read this one
     * list, so a tenth model is added in a single place.
     *
     * @return array<class-string<Model>, string>
     */
    public static function modelStubs(): array
    {
        return [
            Central\Tenant::class => 'Central/Tenant',
            Domain::class => 'Central/Domain',
            CentralUser::class => 'Central/CentralUser',
            Subscription::class => 'Central/Subscription',
            PaymentPlan::class => 'Central/PaymentPlan',
            TenantProvision::class => 'Central/TenantProvision',
            Invitation::class => 'Central/Invitation',
            OwnershipNomination::class => 'Central/OwnershipNomination',
            SocialAccount::class => 'Central/SocialAccount',
            TenantModels\User::class => 'Tenant/User',
        ];
    }

    /**
     * Which class the package should use for one of its models, so your own
     * subclass is used everywhere the package queries it.
     *
     * The three-step resolution order (config, convention, package class)
     * lives on {@see ModelResolver::resolve()}.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<TModel>  $model
     * @return class-string<TModel>
     */
    public static function model(string $model): string
    {
        return ModelResolver::resolve($model);
    }

    /**
     * Clear {@see self::model()}'s memoization, and `Tenant`'s column
     * introspection. Runs on every boot, since both caches are static and
     * would otherwise outlive an application instance under Octane or in
     * tests. {@see ModelResolver::flush()}.
     */
    public static function resetModelCache(): void
    {
        ModelResolver::flush();
        Central\Tenant::flushColumnCache();
    }

    /**
     * Register routes yourself, in place of {@see self::routes()}. The
     * package's central-domain and `tenant` route groups are skipped
     * entirely; re-register any you still want inside `$callback`, which
     * receives the `Application` instance.
     */
    public static function registerRoutesUsing(?Closure $callback): void
    {
        RouteLoader::$registerCallback = $callback;
    }

    /**
     * Register middleware yourself. The package's aliases, groups and trust
     * configuration are skipped entirely; `$callback` receives the
     * `Application` instance.
     */
    public static function registerMiddlewareUsing(?Closure $callback): void
    {
        MiddlewareRegistrar::$registerCallback = $callback;
    }

    /**
     * Register exception handling yourself. The package's context callback,
     * duplicate suppression and throttle are skipped entirely; `$callback`
     * receives the `Exceptions` instance.
     */
    public static function registerExceptionsUsing(?Closure $callback): void
    {
        ExceptionRegistrar::$registerCallback = $callback;
    }
}
