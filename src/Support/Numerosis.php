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
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;
use Throwable;

class Numerosis
{
    /** @var list<string> */
    private static array $tenantColumns = [];

    /** @var array<class-string, class-string> */
    private static array $modelCache = [];

    /**
     * Extension hooks a host can set via `registerRoutesUsing()` /
     * `registerBroadcastingUsing()` / `registerMiddlewareUsing()` to run
     * its own logic right after the package's own route groups /
     * broadcasting routes+channels / middleware aliases-and-groups are
     * registered — without having to override
     * `NumerosisServiceProvider::packageBooted()` wholesale to add one
     * extra route or middleware alias. Each closure receives the
     * `Application` instance, matching how the package's own registration
     * code reaches it (`$this->app` in the provider, `app()` in `routes()`
     * — both static contexts here).
     */
    public static ?Closure $registerRoutesCallback = null;

    public static ?Closure $registerBroadcastingCallback = null;

    public static ?Closure $registerMiddlewareCallback = null;

    /**
     * Whether `routes()` has run yet — `NumerosisServiceProvider`'s safety
     * net reads this via {@see self::routesRegistered()} to decide whether
     * it needs to call `routes()` itself. See that method's docblock for why
     * a host that omitted `withRouting(using: Numerosis::routes(...))`
     * entirely gets no other signal that anything is wrong: every URL just
     * 404s.
     */
    private static bool $routesRegistered = false;

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
     * than to a tenant subdomain.
     *
     * This is the implementation behind `request()->isCentralDomain()`, which
     * `NumerosisServiceProvider` registers as a macro for host code. Package
     * code calls the method instead: a macro is invisible to static analysis
     * (`Call to an undefined method Illuminate\Http\Request::isCentralDomain()`
     * at level 9), and a package should not need its own facade sugar to
     * type-check.
     */
    public static function isCentralDomain(?Request $request = null): bool
    {
        $request ??= resolve(Request::class);

        return in_array($request->getHost(), Config::array('tenancy.central_domains'), true);
    }

    /**
     * `bootstrap/app.php` becomes one line:
     * `return Numerosis::configure(basePath: dirname(__DIR__))->create();`.
     * Wraps `Application::configure()` and applies `routes()`,
     * `middleware()` and `exceptions()` the way a host would otherwise have
     * to spell out three separate `->with*()` calls for — see
     * `.claude/rules/package-host-bootstrap.md` for the three-bug incident
     * that shipped from a host doing this by hand and getting two of the
     * three calls wrong. `$basePath` passes straight through to
     * `Application::configure()`; left null, it infers the host's base path
     * the normal Laravel way (`Application::inferBasePath()`), which this
     * method does not need to replicate.
     *
     * Still just a convenience: `routes()`/`middleware()`/`exceptions()`
     * remain public and independently callable for a host that wants
     * `Application::configure()`'s other options (`then:`, `web:`, `api:`,
     * …) and would otherwise have to duplicate this method to get them.
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
     * Central-domain routes register once per entry in `tenancy.central_domains`
     * — the app can sit behind more than one central hostname (e.g. bare apex
     * + `www`) and each needs `routes/web.php` bound to it directly, since
     * stancl's tenant identification never runs for those domains.
     *
     * If a host set `Numerosis::registerRoutesUsing()`, that callback runs
     * *instead of* the block above — full replacement, not an append hook —
     * matching `NumerosisServiceProvider::registerMiddleware()`/
     * `registerBroadcasting()`, which apply the same all-or-nothing rule for
     * their own callbacks. A host that wants the package's defaults plus
     * something extra calls `Route::middleware('web')->domain($domain)->
     * group(...)` / `Route::middleware('tenant')->group(...)` itself inside
     * its own callback — this method has no separate "run defaults" entry
     * point to call back into.
     */
    public static function routes(): void
    {
        self::$routesRegistered = true;

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
     * Whether `routes()` has run at all yet, regardless of which branch —
     * the package's own default groups or a host's `registerRoutesUsing()`
     * override. `NumerosisServiceProvider`'s safety net calls `routes()`
     * itself when this is still false by the time the application has
     * finished booting, which only happens for a host whose
     * `bootstrap/app.php` never called `withRouting(using:
     * Numerosis::routes(...))` at all.
     */
    public static function routesRegistered(): bool
    {
        return self::$routesRegistered;
    }

    /**
     * Absolute path to the package's own `routes/channels.php`, for
     * `bootstrap/app.php`'s `withBroadcasting()`. Same reasoning as
     * `tenantMigrationPath()`: `InstalledVersions::getInstallPath()` is wrong
     * when this package is the root project, and a hardcoded
     * `vendor/nvade/numerosis/...` string is wrong the moment the file moves.
     */
    public static function broadcastChannelsPath(): string
    {
        return dirname(__DIR__, 2).'/routes/channels.php';
    }

    /**
     * Middleware group used by `withBroadcasting()`. `auth:tenant` is
     * hardcoded rather than read off `numerosis.auth.guards.tenant`
     * because broadcasting auth always happens inside tenant context — see
     * .claude/rules/tenant-caching.md for why a mismatched guard here is a
     * cross-tenant identity leak, not a config nicety.
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
     * `bootstrap/app.php`'s `withMiddleware()`. Does not call
     * `preventRequestForgery()` — the host app combines that with
     * `csrfExceptions()` itself, since the except-list is the one piece a
     * consumer is likely to extend.
     */
    public static function middleware(Middleware $middleware): void
    {
        $middleware->alias([
            'invitation.status' => CheckInvitationStatus::class,
            'tenancy.identification' => TenancyServiceProvider::TENANCY_IDENTIFICATION,
            'tenancy.route' => PreventAccessFromCentralDomains::class,
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

        // No explicit host list: the default already trusts `config('app.url')`'s
        // host plus all its subdomains (Illuminate\Http\Middleware\TrustHosts::
        // allSubdomainsOfApplicationUrl()), which is exactly the central-domain
        // + tenant-subdomain shape this app needs — and it's a no-op on `local`/
        // testing envs, so dev hosts never need listing here.
        $middleware->trustHosts();
    }

    /**
     * Laravel's default `Factory::resolveFactoryName()` strips the host
     * app's own `Models\` prefix and rebuilds the factory name under the
     * host's own namespace — correct for a single-repo app, wrong here,
     * since every model factory lives under `Nvade\Numerosis\Database\
     * Factories\*` regardless of whether the model being factoried is the
     * package's own class or a thin-app stub (`App\Models\Central\Tenant
     * extends \Nvade\Numerosis\Models\Central\Tenant {}`) published from
     * `numerosis/stubs/`. Both call sites — `NumerosisServiceProvider`
     * for real apps, `Tests\TestCase` for the package's own suite — must
     * call this one resolver rather than keep separate copies, since a
     * model's factory-namespace segment (`Central\Tenant` → `Central\
     * TenantFactory`) is exactly the kind of detail that drifts between
     * two hand-written copies (see .claude/rules/testing.md's cache-key
     * bullet for the general shape of this trap).
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
     * The reverse of `factoryNameFor()`: given a factory, resolve the model it
     * builds. Laravel's default `Factory::modelName()` resolver rebuilds the
     * model class under the *host* app's namespace (`app()->getNamespace()`),
     * which is right when a host (or this package's own Workbench test
     * harness) has published a concrete stub extending the package model —
     * ~100 of this package's own test files are typed against exactly that
     * stub (`App\Models\Central\Tenant` etc) — and wrong for a fresh consumer
     * with no stubs published at all, where the package model itself (always
     * concrete, never requires a stub) is the only class that exists.
     * Resolution order is therefore "prefer the stub if one exists," not
     * "prefer the stub if the package model is abstract" — the package model
     * is never abstract, so the latter would never fall through to the stub.
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
     * Absolute path to the package's own tenant migrations, for a host's
     * `config('tenancy.migration_parameters')['--path']`. `__DIR__`-relative
     * for the same reason the Filament plugins' discovery paths are:
     * `InstalledVersions::getInstallPath()` is wrong when this package is the
     * root project (Workbench), and a hardcoded `vendor/nvade/numerosis/...`
     * string is wrong the moment the file moves. Pointing tenancy config at
     * this instead of a published copy under `database/migrations/tenant` is
     * the intended shape — publishing that tag remains the opt-in escape
     * hatch for a host that needs to customise a migration.
     */
    public static function tenantMigrationPath(): string
    {
        return dirname(__DIR__, 2).'/database/migrations/tenant';
    }

    /**
     * The `numerosis-assets` publish group: package source directory =>
     * host target directory. `NumerosisServiceProvider::packageBooted()`
     * feeds this straight to `publishGroup()`, and `InstallNumerosisCommand`
     * uses the same map to diff a host's published copy against the
     * original — one map, so the two can't drift apart from each other.
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
     * The `<link>`/`<script>` tags `resources/views/partials/styles.blade.php`
     * needs for this package's non-panel styling (`dist/numerosis.css`) and
     * the merged central/tenant JS (`dist/numerosis.js` — see
     * `resources/js/numerosis.js`'s own docblock for why central.js and
     * tenant.js became one file). Both are prebuilt and registered as
     * Filament assets (`NumerosisServiceProvider::registerFilamentTheme()`),
     * copied to `public/{js,css}/nvade/numerosis/` by the `filament:assets`
     * command a host already runs — the zero-config path needs nothing
     * else.
     *
     * The CSS has no publish-and-customise escape hatch (a host overrides
     * its look through `tokens.css` custom properties instead, same as
     * `dist/filament-theme.css`), but the JS does — `numerosis-assets`
     * publishes `resources/js/numerosis.js` as an ordinary source file a
     * host can edit and point their own `vite.config.js` at. When a
     * published copy exists, this tries `@vite(['resources/js/numerosis.js'])`
     * first and only falls back to the prebuilt `<script>` tag if the
     * host's own Vite manifest doesn't actually have an entry for it yet
     * (published but not wired into `vite.config.js`) — `ViteException`
     * covers both `Illuminate\Foundation\Vite`'s missing-manifest and
     * missing-entry cases, since `ViteManifestNotFoundException extends
     * ViteException`.
     */
    public static function assetTags(): Htmlable
    {
        $css = '<link href="'.e(FilamentAsset::getStyleHref(NumerosisServiceProvider::ASSET_ID, 'nvade/numerosis')).'" rel="stylesheet" />';

        if (File::exists(resource_path('js/numerosis.js'))) {
            try {
                return new HtmlString($css.app(Vite::class)(['resources/js/numerosis.js'])->toHtml());
            } catch (ViteException) {
                // Published but not (yet) in the host's own Vite manifest —
                // fall through to the prebuilt <script> tag below.
            }
        }

        $js = '<script src="'.e(FilamentAsset::getScriptSrc(NumerosisServiceProvider::ASSET_ID, 'nvade/numerosis')).'"></script>';

        return new HtmlString($css.$js);
    }

    /**
     * Exception context/throttling for `bootstrap/app.php`'s
     * `withExceptions()`. Reads `tenancy()->initialized` at **report** time,
     * which is wrong for a job that failed inside `$tenant->run()` — see
     * .claude/rules/exception-handling.md. `TagsSentryScopeWithTenant` is the
     * existing fix for that case; this method is not it.
     *
     * Does not call `Integration::handles($exceptions)` — Sentry is a
     * `suggest`, wiring it stays with the host.
     */
    public static function exceptions(Exceptions $exceptions): void
    {
        $exceptions->context(function (): array {
            // Reporting must never itself throw: this closure can run before
            // Facade::setFacadeApplication() has been called at all — when
            // the exception being reported was thrown *during* application
            // bootstrap, ahead of the RegisterFacades bootstrapper — in
            // which case every facade call below throws "A facade root has
            // not been set", turning a reportable boot failure into an
            // uncaught fatal that masks the real error and crash-loops the
            // process. TrustHostsBootstrapper-style — best-effort context,
            // never a hard dependency.
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
     * Resolve the class one of the package's own call sites should use for a
     * given model. The 9 models this covers (`Tenant`, `Domain`,
     * `CentralUser`, `Subscription`, `PaymentPlan`, `PendingTenantProvision`,
     * `Tenant\Invitation`, `Tenant\Module`, `Tenant\User`) are concrete, so
     * calling `Tenant::query()` etc directly always works — this is a pure
     * override mechanism, not a requirement.
     *
     * Three-step resolution, config first: (1) `numerosis.models.{$model}`
     * — explicit, always wins, set in the host's own config file (no `.env`
     * key any more — see the array's docblock in config/numerosis.php).
     * (2) `App\Models\<suffix>` (host's app namespace,
     * same suffix `factoryNameFor()`/`modelNameFor()` derive) when that class
     * exists *and* is a subclass of `$model` — a host that names its
     * subclass exactly where Laravel convention expects it needs no config
     * at all. (3) `$model` unchanged, the package's own class.
     *
     * `is_subclass_of()` is what makes step 2 safe: it only fires for a class
     * that genuinely extends `$model`, so a host with an unrelated
     * `App\Models\Central\Tenant` for some other purpose is never silently
     * redirected into it — that class fails the subclass check and step 3
     * falls through to the package's own class instead. This is a narrower
     * version of the by-convention fallback D8
     * (`.claude/plans/package-extraction.md`) removed from this exact
     * method — that one guessed a class name with no
     * subclass check and ran against *abstract* package models, so a guess
     * that resolved to nothing crashed on instantiation; this fires only
     * against a verified-compatible concrete class, and the worst case of a
     * wrong guess is "resolves to nothing," which step 3 already handles.
     * `modelNameFor()` already runs the same class_exists half of this check
     * for factories with no incident since D8 shipped.
     *
     * Memoized per request — `app()->getNamespace()` and `is_subclass_of()`
     * are not free, and this method sits on ~108 call sites.
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
     * Clears `model()`'s memoization. Called from
     * `NumerosisServiceProvider::packageRegistered()` on every boot — the
     * cache is a bare static array, not container-bound, so nothing else
     * clears it between Testbench's per-test `Application` rebuilds or
     * between requests in a long-running (Octane) worker.
     */
    public static function resetModelCache(): void
    {
        self::$modelCache = [];
    }

    /**
     * Replace `routes()`'s default central-domain and `tenant` route-group
     * registration with `$callback` entirely — the package's own groups are
     * not registered when this is set. `$callback` receives the
     * `Application` instance.
     */
    public static function registerRoutesUsing(Closure $callback): void
    {
        self::$registerRoutesCallback = $callback;
    }

    /**
     * Replace `NumerosisServiceProvider::registerBroadcasting()`'s default
     * `/broadcasting/auth` route + `routes/channels.php` registration with
     * `$callback` entirely — the package's own registration is skipped when
     * this is set. `$callback` receives the `Application` instance.
     */
    public static function registerBroadcastingUsing(Closure $callback): void
    {
        self::$registerBroadcastingCallback = $callback;
    }

    /**
     * Replace `NumerosisServiceProvider::registerMiddleware()`'s default
     * middleware aliases/groups/trusted-proxy registration with `$callback`
     * entirely — the package's own registration is skipped when this is
     * set. `$callback` receives the `Application` instance.
     */
    public static function registerMiddlewareUsing(Closure $callback): void
    {
        self::$registerMiddlewareCallback = $callback;
    }
}
