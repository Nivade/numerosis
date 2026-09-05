<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Support;

use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\ApplicationBuilder;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Fortify as FortifyFacade;
use Laravel\Fortify\RoutePath;
use Nvade\Numerosis\Contracts\Exceptions\ProvidesExceptionContext;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Enums\Tenancy\IdentificationMode;
use Nvade\Numerosis\Features\Auth\OneTimePasswordFeature;
use Nvade\Numerosis\Http\Controllers\Auth\OneTimePasswordChallengeController;
use Nvade\Numerosis\Http\Middleware\Authenticate;
use Nvade\Numerosis\Http\Middleware\EnsureSessionMatchesTenant;
use Nvade\Numerosis\Http\Middleware\EnsureTenantSubscriptionActive;
use Nvade\Numerosis\Http\Middleware\InitializeTenancy;
use Nvade\Numerosis\Http\Middleware\RequirePasswordIfSet;
use Nvade\Numerosis\Http\Middleware\TenantRouteGuard;
use Nvade\Numerosis\Models\Central;
use Nvade\Numerosis\Models\Tenant as TenantModels;
use ReflectionClass;
use Stancl\Tenancy\Resolvers\PathTenantResolver;
use WeakMap;

/**
 * The package's entry point for a host's `bootstrap/app.php`, and the front
 * door to every seam a host or satellite uses. Implements routing, middleware,
 * and exception handling itself; the rest of its methods delegate to the
 * classes below, so that `Numerosis::` stays the one idiom a host needs.
 *
 * @see ModelResolver model and factory resolution
 * @see Assets front-end publishing
 */
class Numerosis
{
    /**
     * Set through {@see self::registerRoutesUsing()} and
     * {@see self::registerMiddlewareUsing()}. Each replaces the package's
     * own registration and receives the `Application` instance.
     */
    public static ?Closure $registerRoutesCallback = null;

    public static ?Closure $registerMiddlewareCallback = null;

    /**
     * Set through {@see self::registerExceptionsUsing()}. Receives the
     * `Exceptions` instance, not the `Application`, because it runs before
     * the container exists.
     */
    public static ?Closure $registerExceptionsCallback = null;

    private static bool $routesRegistered = false;

    private static bool $middlewareRegistered = false;

    private static bool $authRoutesEnabled = true;

    /**
     * Tracks which `Handler` instances {@see self::exceptions()} has already
     * registered against.
     *
     * @var WeakMap<Handler, true>|null
     */
    private static ?WeakMap $exceptionsRegisteredFor = null;

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
     * `routes/web.php`, `routes/tenant.php` and `routes/api.php` in the host's
     * own base path are loaded alongside the package's, each optional.
     *
     * @see self::registerRoutesUsing()
     */
    public static function routes(bool $withAuth = true, string $apiPrefix = 'api'): void
    {
        self::$routesRegistered = true;
        self::$authRoutesEnabled = $withAuth;

        if (self::$registerRoutesCallback instanceof Closure) {
            (self::$registerRoutesCallback)(app());

            return;
        }

        $routes = dirname(__DIR__, 2).'/routes';
        $hostWeb = base_path('routes/web.php');
        $hostTenant = base_path('routes/tenant.php');

        foreach (Config::array('tenancy.central_domains') as $domain) {
            if (! is_string($domain)) {
                continue;
            }

            Route::middleware('web')
                ->domain($domain)
                ->group(function () use ($routes, $hostWeb, $withAuth): void {
                    require $routes.'/web.php';

                    if ($withAuth) {
                        self::loadAuthRoutes(Context::Central->guard());
                    }

                    // Last, because `RouteCollection` keys on method + domain
                    // + URI: a host route on `/` replaces the package's only
                    // by being registered after it.
                    if (is_file($hostWeb)) {
                        require $hostWeb;
                    }
                });
        }

        $tenantRoutes = Route::middleware('tenant');

        // Without this prefix every route in `routes/tenant.php` is
        // unreachable in path mode, 404ing like any unknown path. The
        // parameter name is the one stancl's own middleware reads back out.
        if (IdentificationMode::current() === IdentificationMode::Path) {
            $tenantRoutes = $tenantRoutes->prefix('{'.PathTenantResolver::$tenantParameterName.'}');
        }

        $tenantRoutes->group(function () use ($routes, $hostTenant, $withAuth): void {
            require $routes.'/tenant.php';

            if ($withAuth) {
                self::loadAuthRoutes(Context::Tenant->guard());
            }

            if (is_file($hostTenant)) {
                require $hostTenant;
            }
        });

        // Outside the domain groups, matching what `withRouting(api: ...)`
        // would have built had `using:` left it reachable.
        if (is_file($api = base_path('routes/api.php'))) {
            Route::middleware('api')->prefix($apiPrefix)->group($api);
        }
    }

    /**
     * Every auth route, into whichever group is open: once per central domain
     * under the central guard, once for the tenant group under the tenant one.
     */
    private static function loadAuthRoutes(string $guard): void
    {
        self::loadFortifyRoutes($guard);
        self::loadOneTimePasswordRoutes($guard);
    }

    /**
     * Loads Fortify's own `routes/routes.php`. Both keys set here are baked
     * into route middleware at registration time; the `finally` restores them
     * because a leftover `fortify.guard` changes the process-wide default.
     */
    private static function loadFortifyRoutes(string $guard): void
    {
        $original = Config::array('fortify');

        Config::set('fortify.guard', $guard);
        Config::set('fortify.middleware', []);

        try {
            require self::fortifyRoutesPath();
        } finally {
            Config::set('fortify', $original);
        }
    }

    private static function fortifyRoutesPath(): string
    {
        $reflection = new ReflectionClass(FortifyFacade::class);

        return dirname((string) $reflection->getFileName(), 2).'/routes/routes.php';
    }

    /**
     * Registers the `OneTimePasswordFeature` challenge routes into the open
     * group, matching {@see self::loadFortifyRoutes()}. Paths go through
     * `RoutePath::for()`, so `config('fortify.paths')` overrides them like
     * every neighbouring auth URL, and the verify leg carries its own limiter
     * because its request has no `email` field to key on.
     */
    private static function loadOneTimePasswordRoutes(string $guard): void
    {
        if (! OneTimePasswordFeature::available()) {
            return;
        }

        $path = RoutePath::for('one-time-password.login', '/one-time-password-challenge');

        Route::get($path, [OneTimePasswordChallengeController::class, 'create'])
            ->middleware('guest:'.$guard)
            ->name('one-time-password.login');

        Route::post($path, [OneTimePasswordChallengeController::class, 'store'])
            ->middleware([
                'guest:'.$guard,
                'throttle:'.OneTimePasswordFeature::LIMITER,
            ])
            ->name('one-time-password.login.store');
    }

    /**
     * Whether {@see self::routes()} has run yet. If it has not by the time the
     * application finishes booting, meaning `bootstrap/app.php` never called
     * `withRouting(using: Numerosis::routes(...))`, the service provider calls
     * it, so the omission costs nothing and every URL still resolves.
     */
    public static function routesRegistered(): bool
    {
        return self::$routesRegistered;
    }

    /**
     * Whether {@see self::middleware()} has run yet. `NumerosisServiceProvider`
     * stands down when it has, so trust configuration and alias swaps made
     * after it in the same `withMiddleware()` closure survive.
     */
    public static function middlewareRegistered(): bool
    {
        return self::$middlewareRegistered;
    }

    /**
     * For tests only. The flag is a process-lifetime static, so one test
     * calling {@see self::middleware()} would otherwise stand the service
     * provider down for every test after it in the same worker.
     */
    public static function resetMiddlewareRegisteredForTesting(): void
    {
        self::$middlewareRegistered = false;
    }

    /**
     * Whether `routes/web.php` should require `routes/auth.php`. Read there,
     * set by {@see self::routes()}'s `$withAuth` argument.
     */
    public static function authRoutesEnabled(): bool
    {
        return self::$authRoutesEnabled;
    }

    /**
     * Paths exempt from CSRF verification: Stripe and Telescope both post to
     * this app from outside a browser session, so a CSRF token is never
     * available on those requests.
     *
     * @return list<string>
     */
    public static function csrfExceptions(): array
    {
        return ['stripe/*', 'billing/webhook', 'telescope/*'];
    }

    /**
     * Every alias the package registers, read by both {@see self::middleware()}
     * and `NumerosisServiceProvider::registerMiddleware()`. One registry: an
     * alias present in only one of the two works in this repo and fails in a
     * host, or the reverse.
     *
     * This array must stay pure class-string literals with no config or
     * container read: `Numerosis::middleware()` runs from a host's
     * `bootstrap/app.php`, before `RegisterFacades`, so anything evaluated
     * here that touches `Config`/`Facade` fatals or silently defaults on a
     * real boot. `tenancy.identification`/`tenancy.route` therefore alias to
     * delegating middleware that picks the real class from
     * `IdentificationMode::current()` at request time instead.
     *
     * @return array<string, string>
     */
    public static function middlewareAliases(): array
    {
        return [
            // Laravel's `auth`, plus the central-to-tenant session promotion.
            // It takes its own alias because `auth` is the host's, and every
            // central route using that one must keep Laravel's behaviour.
            'tenancy.auth' => Authenticate::class,

            'password.confirm.if-set' => RequirePasswordIfSet::class,

            // Apply the suspension gate per route group. It redirects to
            // `tenant.suspended`, itself a tenant route, so putting it on the
            // `tenant` group as a whole loops.
            'tenancy.subscription' => EnsureTenantSubscriptionActive::class,

            'tenancy.identification' => InitializeTenancy::class,
            'tenancy.route' => TenantRouteGuard::class,
            'tenancy.session' => EnsureSessionMatchesTenant::class,
        ];
    }

    /**
     * The groups this package defines, read by the same two callers as
     * {@see self::middlewareAliases()}.
     *
     * @return array<string, list<string>>
     */
    public static function middlewareGroups(): array
    {
        return [
            'tenant' => [
                'web',
                'tenancy.identification',
                'tenancy.route',
                'tenancy.session',
            ],
            'universal' => [],
        ];
    }

    /**
     * Middleware aliases, groups, and trust configuration for
     * `bootstrap/app.php`'s `withMiddleware()`. CSRF is left alone: pass
     * {@see self::csrfExceptions()} to `preventRequestForgery()` yourself,
     * so you can add your own exempt paths to the list.
     *
     * Call it first in the closure: anything you configure afterwards wins,
     * and `NumerosisServiceProvider` no longer re-applies these at boot.
     */
    public static function middleware(Middleware $middleware): void
    {
        self::$middlewareRegistered = true;

        $middleware->alias(self::middlewareAliases());

        foreach (self::middlewareGroups() as $name => $stack) {
            $middleware->group($name, $stack);
        }

        $middleware->trustProxies('*');

        // Laravel's default trusts config('app.url') and all its subdomains,
        // which already covers the central domain plus every tenant subdomain.
        $middleware->trustHosts();

        // No `redirectGuestsTo()` here: `registerGuestRedirect()` calls
        // `Authenticate::redirectUsing()` on every boot, which overrides what
        // `ApplicationBuilder::withMiddleware()` set before this callback ran.
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
     * @param  class-string<Factory<Model>>  $factoryName
     * @return class-string<Model>
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
        return dirname(__DIR__, 2).'/database/migrations/tenant';
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
     * Exception context and throttling for `bootstrap/app.php`'s
     * `withExceptions()`. Adds the current tenant, guard and user to every
     * report, and no-ops on a `Handler` it has already registered against. A
     * job that failed inside `$tenant->run()` needs
     * {@see \Nvade\Numerosis\Concerns\TagsSentryScopeWithTenant} to be tagged.
     */
    public static function exceptions(Exceptions $exceptions): void
    {
        if (self::$registerExceptionsCallback instanceof Closure) {
            (self::$registerExceptionsCallback)($exceptions);

            return;
        }

        self::$exceptionsRegisteredFor ??= new WeakMap;

        if (isset(self::$exceptionsRegisteredFor[$exceptions->handler])) {
            return;
        }

        self::$exceptionsRegisteredFor[$exceptions->handler] = true;

        // Resolved at report time, not here: `exceptions()` runs before the
        // container exists.
        $exceptions->context(fn (): array => app(ProvidesExceptionContext::class)->handle());

        $exceptions->dontReportDuplicates();

        $exceptions->throttle(fn () => Limit::perMinute(30));
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
            Central\Domain::class => 'Central/Domain',
            Central\CentralUser::class => 'Central/CentralUser',
            Central\Subscription::class => 'Central/Subscription',
            Central\PaymentPlan::class => 'Central/PaymentPlan',
            Central\PendingTenantProvision::class => 'Central/PendingTenantProvision',
            Central\Invitation::class => 'Central/Invitation',
            Central\SocialAccount::class => 'Central/SocialAccount',
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
    public static function registerRoutesUsing(Closure $callback): void
    {
        self::$registerRoutesCallback = $callback;
    }

    /**
     * Register middleware yourself. The package's aliases, groups and trust
     * configuration are skipped entirely; `$callback` receives the
     * `Application` instance.
     */
    public static function registerMiddlewareUsing(Closure $callback): void
    {
        self::$registerMiddlewareCallback = $callback;
    }

    /**
     * Register exception handling yourself. The package's context callback,
     * duplicate suppression and throttle are skipped entirely; `$callback`
     * receives the `Exceptions` instance.
     */
    public static function registerExceptionsUsing(Closure $callback): void
    {
        self::$registerExceptionsCallback = $callback;
    }
}
