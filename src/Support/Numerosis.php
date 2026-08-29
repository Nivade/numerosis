<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Support;

use Closure;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\ApplicationBuilder;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Foundation\Vite;
use Illuminate\Foundation\ViteException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;
use Nvade\Numerosis\Http\Middleware\CheckInvitationStatus;
use Nvade\Numerosis\Http\Middleware\EnsureSessionMatchesTenant;
use Nvade\Numerosis\Models\User as NumerosisUser;
use Nvade\Numerosis\NumerosisServiceProvider;
use Nvade\Numerosis\Providers\TenancyServiceProvider;
use Nvade\Numerosis\Support\Tenancy\TenancyVersion;
use Stancl\Tenancy\Contracts\Tenant;
use Throwable;
use WeakMap;

class Numerosis
{
    /** @var list<string> */
    private static array $tenantColumns = [];

    /** @var array<class-string, class-string> */
    private static array $modelCache = [];

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
     * @param  list<string>  $columns
     */
    public static function addTenantColumns(array $columns): void
    {
        self::$tenantColumns = array_values(array_merge(self::$tenantColumns, $columns));
    }

    /**
     * @return list<string>
     */
    public static function tenantColumns(): array
    {
        return self::$tenantColumns;
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
     * {@see self::registerRoutesUsing()} replaces this wholesale; there is no
     * hook to append to the defaults.
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
                ->group($routes.'/web.php');
        }

        Route::middleware('tenant')->group($routes.'/tenant.php');
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
            'tenancy.identification' => TenancyServiceProvider::TENANCY_IDENTIFICATION,
            'tenancy.route' => TenancyVersion::preventAccessFromCentralDomainsMiddleware(),
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
    }

    /**
     * Resolve the factory for a model, keeping the namespace segment below
     * `Models\` (`Central\Tenant` → `Central\TenantFactory`). Registered as
     * Laravel's factory-name resolver, because every factory ships with this
     * package even when the model is a subclass in your own app namespace.
     *
     * This replaces Laravel's *global* resolver, so it also answers for models
     * of your own that have nothing to do with this package: any class under a
     * `\Models\` namespace — `App\Models\User` included — resolves to
     * `Nvade\Numerosis\Database\Factories\<suffix>Factory`. The failure that
     * causes is not a wrong class but wrong *fields*: {@see
     * self::modelNameFor()} still instantiates your model, so
     * `App\Models\User::factory()` builds your model from the package
     * factory's definition, silently missing whatever columns your own
     * migrations added. Escape it per model with Laravel's own attribute,
     * which `HasFactory::newFactory()` consults before any global resolver:
     *
     * ```php
     * #[UseFactory(\Database\Factories\UserFactory::class)]
     * class User extends Authenticatable {}
     * ```
     *
     * @param  class-string<Model>  $modelName
     * @return class-string<Factory<Model>>
     */
    public static function factoryNameFor(string $modelName): string
    {
        $suffix = str_contains($modelName, '\\Models\\')
            ? substr($modelName, strpos($modelName, '\\Models\\') + strlen('\\Models\\'))
            : class_basename($modelName);

        /** @var class-string<Factory<Model>> $factoryName */
        $factoryName = 'Nvade\\Numerosis\\Database\\Factories\\'.$suffix.'Factory';

        return $factoryName;
    }

    /**
     * The reverse of {@see self::factoryNameFor()}. Prefers a subclass in
     * your own app namespace when one exists, so factories build the model
     * you actually extended, and falls back to the package's own class.
     *
     * @param  class-string<Factory<Model>>  $factoryName
     * @return class-string<Model>
     */
    public static function modelNameFor(string $factoryName): string
    {
        $suffix = str_contains($factoryName, '\\Database\\Factories\\')
            ? substr($factoryName, strpos($factoryName, '\\Database\\Factories\\') + strlen('\\Database\\Factories\\'))
            : class_basename($factoryName);

        $suffix = preg_replace('/Factory$/', '', $suffix) ?? $suffix;

        $hostModel = rtrim((string) app()->getNamespace(), '\\').'\\Models\\'.$suffix;

        if (class_exists($hostModel)) {
            /** @var class-string<Model> $hostModel */
            return $hostModel;
        }

        /** @var class-string<Model> $packageModel */
        $packageModel = 'Nvade\\Numerosis\\Models\\'.$suffix;

        return $packageModel;
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
     * The `numerosis-assets` publish group, as source => target directory.
     * `numerosis:install` diffs published copies against the same map to
     * report when yours has fallen behind the package's.
     *
     * @return array<string, string>
     */
    public static function assetSourcePaths(): array
    {
        $base = dirname(__DIR__, 2);

        return [
            $base.'/resources/css' => resource_path('css'),
            $base.'/resources/js' => resource_path('js'),
        ];
    }

    /**
     * The `<link>`/`<script>` tags for the package's non-panel CSS and JS.
     * Both ship prebuilt and are served by `filament:assets`, so no build
     * step is required.
     *
     * Publishing `numerosis-assets` gives you `resources/js/numerosis.js` to
     * edit; once it is also an entry in your `vite.config.js`, your build is
     * used instead of the prebuilt bundle. Override the CSS through the
     * custom properties in `tokens.css` rather than by publishing it.
     */
    public static function assetTags(): Htmlable
    {
        $css = '<link href="'.e(FilamentAsset::getStyleHref(NumerosisServiceProvider::ASSET_ID, 'nvade/numerosis')).'" rel="stylesheet" />';

        if (File::exists(resource_path('js/numerosis.js'))) {
            try {
                return new HtmlString($css.app(Vite::class)(['resources/js/numerosis.js'])->toHtml());
            } catch (ViteException) {
                // Published, but not an entry in the host's Vite manifest yet.
            }
        }

        $js = '<script src="'.e(FilamentAsset::getScriptSrc(NumerosisServiceProvider::ASSET_ID, 'nvade/numerosis')).'"></script>';

        return new HtmlString($css.$js);
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
     * Resolve which class the package should use for one of its models,
     * so that your own subclass is used everywhere the package queries it.
     *
     * Resolution order:
     *
     * 1. `config('numerosis.models.{$model}')`, if set.
     * 2. The same class name under your app namespace (`App\Models\Central\
     *    Tenant` for `Nvade\Numerosis\Models\Central\Tenant`), if it exists
     *    and extends the package model — so a conventionally-named subclass
     *    needs no config at all. An unrelated class of that name is ignored.
     * 3. The package's own class.
     *
     * Every model this covers is concrete, so overriding is optional.
     * Results are memoized for the lifetime of the process.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<TModel>  $model
     * @return class-string<TModel>
     */
    public static function model(string $model): string
    {
        if (isset(self::$modelCache[$model])) {
            /** @var class-string<TModel> */
            return self::$modelCache[$model];
        }

        $override = Config::get("numerosis.models.{$model}");

        if (is_string($override) && $override !== '') {
            /** @var class-string<TModel> $override */
            return self::$modelCache[$model] = $override;
        }

        $suffix = str_contains($model, '\\Models\\')
            ? substr($model, strpos($model, '\\Models\\') + strlen('\\Models\\'))
            : class_basename($model);

        $hostModel = rtrim((string) app()->getNamespace(), '\\').'\\Models\\'.$suffix;

        if (class_exists($hostModel) && is_subclass_of($hostModel, $model)) {
            /** @var class-string<TModel> $hostModel */
            return self::$modelCache[$model] = $hostModel;
        }

        return self::$modelCache[$model] = $model;
    }

    /**
     * Clear {@see self::model()}'s memoization. Runs on every boot, since the
     * cache is static and would otherwise outlive an application instance
     * under Octane or in tests.
     */
    public static function resetModelCache(): void
    {
        self::$modelCache = [];
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
