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
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Fortify as FortifyFacade;
use Nvade\Numerosis\Enums\Tenancy\IdentificationMode;
use Nvade\Numerosis\Http\Middleware\CheckInvitationStatus;
use Nvade\Numerosis\Http\Middleware\EnsureSessionMatchesTenant;
use Nvade\Numerosis\Models\User as NumerosisUser;
use Nvade\Numerosis\Providers\TenancyServiceProvider;
use ReflectionClass;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Resolvers\PathTenantResolver;
use Throwable;
use WeakMap;

/**
 * The package's entry point for a host's `bootstrap/app.php`, and the front
 * door to every seam a host or satellite uses.
 *
 * Three audiences were split out of this class on 2026-09-01 —
 * {@see ModelResolver} (model and factory resolution), {@see Contributions}
 * (what other packages have added) and {@see Assets} (front-end publishing).
 * What is left is application bootstrap: routing, middleware, broadcasting,
 * exception handling, and the three `registerXUsing()` overrides that replace
 * one of those wholesale.
 *
 * **Every moved method still exists here and delegates.** That is the point of
 * the split: `Numerosis::` is the documented idiom in `docs/extending.md`,
 * every host's `config/numerosis.php`, and ~200 call sites. The implementation
 * moved; the seam did not.
 */
class Numerosis
{
    /**
     * Set through {@see self::registerRoutesUsing()},
     * {@see self::registerBroadcastingUsing()} and
     * {@see self::registerMiddlewareUsing()}. Each replaces the package's
     * own registration and receives the `Application` instance.
     */
    public static ?Closure $registerRoutesCallback = null;

    public static ?Closure $registerBroadcastingCallback = null;

    public static ?Closure $registerMiddlewareCallback = null;

    private static bool $routesRegistered = false;

    private static bool $authRoutesEnabled = true;

    /**
     * Tracks which `Handler` instances {@see self::exceptions()} has already
     * registered against, so calling it twice for the *same* singleton
     * (host's own `withExceptions()` closure plus `NumerosisServiceProvider::
     * registerExceptionHandling()`'s fallback) is a no-op the second time.
     * Keyed by instance rather than a plain bool so a fresh `Handler` built
     * for a test, or for a new application under Octane, is never blocked by
     * a previous one's registration.
     *
     * @var WeakMap<Handler, true>|null
     */
    private static ?WeakMap $exceptionsRegisteredFor = null;

    /**
     * Add columns to the central `tenants` table's fillable/virtual set.
     * {@see Contributions::addTenantColumns()}.
     *
     * @param  list<string>  $columns
     */
    public static function addTenantColumns(array $columns): void
    {
        Contributions::addTenantColumns($columns);
    }

    /**
     * {@see Contributions::tenantColumns()}.
     *
     * @return list<string>
     */
    public static function tenantColumns(): array
    {
        return Contributions::tenantColumns();
    }

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
     * Reduces `bootstrap/app.php` to one line:
     *
     * ```php
     * return Numerosis::configure(basePath: dirname(__DIR__))->create();
     * ```
     *
     * Applies {@see self::routes()}, {@see self::middleware()} and
     * {@see self::exceptions()} to a standard `Application::configure()`
     * builder. To use `configure()`'s other options (`then:`, `api:`, …),
     * skip this method and wire those three up yourself — they are public
     * and independently callable.
     */
    public static function configure(?string $basePath = null): ApplicationBuilder
    {
        return Application::configure(basePath: $basePath)
            ->withRouting(using: self::routes(...))
            ->withMiddleware(self::middleware(...))
            ->withExceptions(self::exceptions(...));
    }

    /**
     * Route registration for `bootstrap/app.php`'s `withRouting(using: ...)`.
     * Central routes are bound to each hostname in `tenancy.central_domains`
     * separately, because tenant identification never runs for those domains.
     *
     * Pass `withAuth: false` if you keep an auth system of your own —
     * `routes/auth.php` is skipped, and everything else in `routes/web.php`
     * (billing webhook, checkout, registration wizard, account pages) still
     * registers:
     *
     * ```php
     * ->withRouting(using: fn () => Numerosis::routes(withAuth: false))
     * ```
     *
     * That flag exists because those four route *names* — `login`,
     * `register`, `logout`, `verification.verify` — are registered
     * unconditionally otherwise, behind no feature flag, so a host with its
     * own Fortify/Breeze routes gets a silent name collision: Laravel keeps
     * whichever was registered last, making "which system serves /login" an
     * artifact of provider order rather than a decision. Everything the
     * package generates from those names (the login redirect, the email
     * verification link) becomes yours to provide under the same names.
     *
     * To *add* routes rather than replace these, use
     * {@see self::addCentralRoutes()} / {@see self::addTenantRoutes()} — they
     * run inside the groups built below, so a contributed central route is
     * bound to the same hostnames the package's own are.
     * {@see self::registerRoutesUsing()} replaces this wholesale, and bypasses
     * both.
     */
    public static function routes(bool $withAuth = true): void
    {
        self::$routesRegistered = true;
        self::$authRoutesEnabled = $withAuth;

        if (self::$registerRoutesCallback instanceof Closure) {
            (self::$registerRoutesCallback)(app());

            return;
        }

        $routes = dirname(__DIR__, 2).'/routes';

        foreach (Config::array('tenancy.central_domains') as $domain) {
            if (! is_string($domain)) {
                continue;
            }

            Route::middleware('web')
                ->domain($domain)
                ->group(function () use ($routes, $withAuth): void {
                    require $routes.'/web.php';

                    if ($withAuth) {
                        self::loadFortifyRoutes(Config::string('numerosis.auth.guards.central'));
                    }

                    foreach (Contributions::centralRouteCallbacks() as $callback) {
                        $callback();
                    }
                });
        }

        $tenantRoutes = Route::middleware('tenant');

        // Path mode identifies the tenant from the first URL segment, so
        // every tenant route has to carry it. The Filament tenant panel used
        // to supply this prefix for its own routes and core's tenant group
        // never had one — which meant `routes/tenant.php` was unreachable in
        // this mode, silently, since a route that never matches 404s like any
        // other unknown path. `PathTenantResolver::$tenantParameterName` is
        // the same name stancl's own middleware reads back out.
        if (IdentificationMode::current() === IdentificationMode::Path) {
            $tenantRoutes = $tenantRoutes->prefix('{'.PathTenantResolver::$tenantParameterName.'}');
        }

        $tenantRoutes->group(function () use ($routes, $withAuth): void {
            require $routes.'/tenant.php';

            if ($withAuth) {
                self::loadFortifyRoutes(Config::string('numerosis.auth.guards.tenant'), passwordBroker: 'tenant');
            }

            foreach (Contributions::tenantRouteCallbacks() as $callback) {
                $callback();
            }
        });
    }

    /**
     * Loads Fortify's own `routes/routes.php` inside whichever group is
     * currently open (a central domain's, or the tenant group's), for the
     * given guard. Fortify normally registers its routes once, inside a
     * single domain/prefix group of its own
     * (`FortifyServiceProvider::configureRoutes()`); `Fortify::ignoreRoutes()`
     * (called in `NumerosisServiceProvider::packageRegistered()`) turns that
     * off, and this is what replaces it — once per central domain, and once
     * for the tenant group, matching every other route file this method
     * requires.
     *
     * `routes/routes.php` bakes `'guest:'.config('fortify.guard')` into route
     * middleware **at registration time**, so the guard has to be correct
     * for whichever group is being built right now. Everything downstream
     * (Fortify's `StatefulGuard` binding, `AuthGuardBootstrapper`) reads the
     * guard at *request* time instead, off `Auth::getDefaultDriver()` — which
     * is why `fortify.guard` is restored in a `finally` rather than left set:
     * leaving it pointing at, say, the tenant guard would make every guard
     * resolution process-wide read the wrong default until the next
     * `loadFortifyRoutes()` call overwrote it, with no error surfaced.
     *
     * `fortify.middleware` is cleared for the same registration-time reason —
     * the outer group already applied `web`/`tenant`, and leaving Fortify's
     * own default (`['web']`) would double it inside the tenant group.
     */
    private static function loadFortifyRoutes(string $guard, ?string $passwordBroker = null): void
    {
        $original = Config::array('fortify');

        Config::set('fortify.guard', $guard);
        Config::set('fortify.middleware', []);
        Config::set('fortify.passwords', $passwordBroker);

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
     * Register central-domain routes alongside `routes/web.php`, without
     * reproducing {@see self::routes()}'s per-domain loop. The callback runs
     * once per configured central domain, inside that domain's own
     * `Route::middleware('web')->domain($domain)` group — so a route added
     * here is bound to the same hostnames the package's own central routes
     * are, automatically.
     *
     * {@see self::registerRoutesUsing()} bypasses this entirely, since it
     * replaces {@see self::routes()} wholesale.
     *
     * `$source` is attribution, not behavior — a package name by convention
     * (e.g. `'nvade/numerosis-onboarding'`). It does nothing on its own;
     * {@see Contributions::centralRouteSources()} is what makes "which
     * package added this route" answerable instead of just "how many".
     */
    public static function addCentralRoutes(Closure $callback, ?string $source = null): void
    {
        Contributions::addCentralRoutes($callback, $source);
    }

    /**
     * Register tenant routes alongside `routes/tenant.php`, inside the same
     * `Route::middleware('tenant')` group. See {@see self::addCentralRoutes()}.
     */
    public static function addTenantRoutes(Closure $callback, ?string $source = null): void
    {
        Contributions::addTenantRoutes($callback, $source);
    }

    /**
     * Clears route contributions registered via {@see self::addCentralRoutes()}
     * / {@see self::addTenantRoutes()}. For tests only — a real host registers
     * these once and they live for the application's lifetime, same as
     * {@see self::$registerRoutesCallback}.
     * {@see Contributions::flushRouteContributions()}.
     */
    public static function resetRouteContributionsForTesting(): void
    {
        Contributions::flushRouteContributions();
    }

    /**
     * Whether {@see self::routes()} has run yet. If it has not by the time
     * the application finishes booting — meaning `bootstrap/app.php` never
     * called `withRouting(using: Numerosis::routes(...))` — the service
     * provider calls it, so the omission costs nothing rather than 404ing
     * every URL.
     */
    public static function routesRegistered(): bool
    {
        return self::$routesRegistered;
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
     * Absolute path to the package's `routes/channels.php`, for
     * `bootstrap/app.php`'s `withBroadcasting()`.
     */
    public static function broadcastChannelsPath(): string
    {
        return dirname(__DIR__, 2).'/routes/channels.php';
    }

    /**
     * Middleware stack for `withBroadcasting()`. The `auth:tenant` guard is
     * fixed, not configurable: broadcasting auth always runs inside tenant
     * context, and authenticating it against any other guard leaks presence
     * channels across tenants.
     *
     * @return list<string>
     */
    public static function broadcasting(): array
    {
        return ['web', 'tenancy.identification', 'tenancy.session', 'auth:tenant', 'universal'];
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
     * Middleware aliases, groups, and trust configuration for
     * `bootstrap/app.php`'s `withMiddleware()`. CSRF is left alone: pass
     * {@see self::csrfExceptions()} to `preventRequestForgery()` yourself,
     * so you can add your own exempt paths to the list.
     */
    public static function middleware(Middleware $middleware): void
    {
        $middleware->alias([
            'invitation.status' => CheckInvitationStatus::class,
            'tenancy.identification' => TenancyServiceProvider::identificationMiddleware(),
            'tenancy.route' => TenancyServiceProvider::tenancyRouteMiddleware(),
            'tenancy.session' => EnsureSessionMatchesTenant::class,
        ]);

        $middleware->group('tenant', [
            'web',
            'tenancy.identification',
            'tenancy.route',
            'tenancy.session',
        ]);

        $middleware->group('universal', []);

        $middleware->trustProxies('*');

        // Laravel's default trusts config('app.url') and all its subdomains,
        // which already covers the central domain plus every tenant subdomain.
        $middleware->trustHosts();

        // ApplicationBuilder::withMiddleware() always registers its own
        // `redirectGuestsTo(fn () => route('login'))` before this callback
        // runs. `NumerosisServiceProvider::registerGuestRedirect()` overrides
        // it unconditionally on every boot (via `Authenticate::redirectUsing()`
        // directly), which covers this path too, so there is nothing to
        // duplicate here.
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
     * this rather than a published copy; publish the migrations only when you
     * need to customise one.
     */
    public static function tenantMigrationPath(): string
    {
        return dirname(__DIR__, 2).'/database/migrations/tenant';
    }

    /**
     * Register a second tenant migration path — for a satellite package
     * shipping its own tenant-database tables. `HostConfig` appends every
     * registered path (this one plus {@see self::tenantMigrationPath()})
     * to `tenancy.migration_parameters['--path']`, the same array a host's
     * own path already lives in.
     * {@see Contributions::addTenantMigrationPath()}.
     */
    public static function addTenantMigrationPath(string $path): void
    {
        Contributions::addTenantMigrationPath($path);
    }

    /**
     * Every path tenancy should migrate: this package's own
     * ({@see self::tenantMigrationPath()}) plus each contributed one. This is
     * what `HostConfig` wants; {@see Contributions::tenantMigrationPaths()}
     * answers the narrower "what did other packages add".
     *
     * @return list<string>
     */
    public static function tenantMigrationPaths(): array
    {
        return [self::tenantMigrationPath(), ...Contributions::tenantMigrationPaths()];
    }

    /**
     * Register a seeder to run after `TenantDatabaseSeeder`'s own
     * `PermissionAndRoleSeeder`/`UserSeeder` calls, for a satellite package
     * seeding its own tenant tables — without a host needing to publish and
     * edit `TenantDatabaseSeeder` itself.
     * {@see Contributions::addTenantSeeder()}.
     *
     * @param  class-string<\Illuminate\Database\Seeder>  $seeder
     */
    public static function addTenantSeeder(string $seeder): void
    {
        Contributions::addTenantSeeder($seeder);
    }

    /**
     * {@see Contributions::tenantSeeders()}.
     *
     * @return list<class-string<\Illuminate\Database\Seeder>>
     */
    public static function tenantSeeders(): array
    {
        return Contributions::tenantSeeders();
    }

    /**
     * The central-database counterpart of {@see self::addTenantSeeder()},
     * run after `DatabaseSeeder`'s own three.
     * {@see Contributions::addCentralSeeder()}.
     *
     * @param  class-string<\Illuminate\Database\Seeder>  $seeder
     */
    public static function addCentralSeeder(string $seeder): void
    {
        Contributions::addCentralSeeder($seeder);
    }

    /**
     * {@see Contributions::centralSeeders()}.
     *
     * @return list<class-string<\Illuminate\Database\Seeder>>
     */
    public static function centralSeeders(): array
    {
        return Contributions::centralSeeders();
    }

    /**
     * Contribute a permission *context* — the noun half of a permission name,
     * e.g. `invitations` in `viewAny invitations` — to `RoleAndPermissionSeeder`,
     * which creates one row per {@see \Nvade\Numerosis\Models\Permission::defaultActions()}
     * action for it under guard `web` and grants them all to `admin`.
     *
     * This exists rather than "register your own seeder" because the failure
     * mode of getting it wrong is total, not local: any navigation that
     * evaluates a resource's `viewAny` to decide its own visibility does so
     * on *every* page render, and Spatie throws `PermissionDoesNotExist`
     * rather than returning false — so one missing context 500s every page
     * carrying that navigation, not just its own screen
     * (`.ai/rules/auth-guards.md`). A satellite
     * shipping a policy-guarded resource must contribute its context here,
     * from its own service provider, before the seeder runs.
     * {@see Contributions::addPermissionContext()}.
     */
    public static function addPermissionContext(string $context): void
    {
        Contributions::addPermissionContext($context);
    }

    /**
     * {@see Contributions::permissionContexts()}.
     *
     * @return list<string>
     */
    public static function permissionContexts(): array
    {
        return Contributions::permissionContexts();
    }

    /**
     * Clears {@see self::addTenantMigrationPath()} / {@see self::addTenantSeeder()} /
     * {@see self::addCentralSeeder()} / {@see self::addPermissionContext()}
     * contributions. For tests only — see {@see self::resetRouteContributionsForTesting()}
     * and {@see Contributions::flushMigrationAndSeederContributions()}.
     */
    public static function resetMigrationAndSeederContributionsForTesting(): void
    {
        Contributions::flushMigrationAndSeederContributions();
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
     * report.
     *
     * Tenancy is read when the exception is *reported*, which is too late
     * for a queued job that failed inside `$tenant->run()` — tenancy has
     * already reverted by then. Compose
     * {@see \Nvade\Numerosis\Concerns\TagsSentryScopeWithTenant} into such
     * jobs to tag them correctly.
     *
     * Idempotent per `Handler` instance: `NumerosisServiceProvider::
     * registerExceptionHandling()` always calls this from `packageBooted()`,
     * so an app that also calls it from its own `bootstrap/app.php` would
     * otherwise register the context callback and throttle twice onto the
     * same real handler. The second call for a given `$exceptions->handler`
     * is a no-op regardless of which one runs first; a different `Handler`
     * instance (a fresh one built for a test, or for a new application under
     * Octane) always registers.
     */
    public static function exceptions(Exceptions $exceptions): void
    {
        self::$exceptionsRegisteredFor ??= new WeakMap;

        if (isset(self::$exceptionsRegisteredFor[$exceptions->handler])) {
            return;
        }

        self::$exceptionsRegisteredFor[$exceptions->handler] = true;

        $exceptions->context(function (): array {
            // Best-effort, never a hard dependency: an exception thrown during
            // bootstrap is reported before facades are available, and letting
            // that fail here would mask the error that actually broke boot.
            try {
                $tenantId = tenancy()->initialized && tenancy()->tenant instanceof Tenant
                    ? (string) tenancy()->tenant->getTenantKey()
                    : null;

                $user = Auth::user();

                return [
                    'tenant_id' => $tenantId,
                    'guard' => Auth::getDefaultDriver(),
                    'user_global_id' => $user instanceof NumerosisUser ? $user->global_id : null,
                ];
            } catch (Throwable) {
                return [];
            }
        });

        $exceptions->dontReportDuplicates();

        $exceptions->throttle(fn () => Limit::perMinute(30));
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
     * Clear {@see self::model()}'s memoization. Runs on every boot, since the
     * cache is static and would otherwise outlive an application instance
     * under Octane or in tests. {@see ModelResolver::flush()}.
     */
    public static function resetModelCache(): void
    {
        ModelResolver::flush();
    }

    /**
     * Register routes yourself instead of {@see self::routes()}. The
     * package's central-domain and `tenant` route groups are skipped
     * entirely; re-register any you still want inside `$callback`, which
     * receives the `Application` instance.
     */
    public static function registerRoutesUsing(Closure $callback): void
    {
        self::$registerRoutesCallback = $callback;
    }

    /**
     * Register broadcasting yourself. The package's `/broadcasting/auth`
     * route and `routes/channels.php` are skipped entirely; `$callback`
     * receives the `Application` instance.
     */
    public static function registerBroadcastingUsing(Closure $callback): void
    {
        self::$registerBroadcastingCallback = $callback;
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
}
