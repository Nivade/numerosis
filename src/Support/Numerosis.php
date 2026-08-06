<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Nvade\Numerosis\Http\Middleware\CheckInvitationStatus;
use Nvade\Numerosis\Http\Middleware\EnsureSessionMatchesTenant;
use Nvade\Numerosis\Models\User as NumerosisUser;
use Nvade\Numerosis\Providers\TenancyServiceProvider;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

class Numerosis
{
    /** @var list<string> */
    private static array $tenantColumns = [];

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
        $request ??= app(Request::class);

        return in_array($request->getHost(), Config::array('tenancy.central_domains'), true);
    }

    /**
     * Route registration for `bootstrap/app.php`'s `withRouting(using: ...)`.
     * Central-domain routes register once per entry in `tenancy.central_domains`
     * — the app can sit behind more than one central hostname (e.g. bare apex
     * + `www`) and each needs `routes/web.php` bound to it directly, since
     * stancl's tenant identification never runs for those domains.
     */
    public static function routes(): void
    {
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
     * hardcoded rather than read off `auth.defaults.guards.context.tenant`
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
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelName
     * @return class-string<\Illuminate\Database\Eloquent\Factories\Factory<\Illuminate\Database\Eloquent\Model>>
     */
    public static function factoryNameFor(string $modelName): string
    {
        $suffix = str_contains($modelName, '\\Models\\')
            ? substr($modelName, strpos($modelName, '\\Models\\') + strlen('\\Models\\'))
            : class_basename($modelName);

        /** @var class-string<\Illuminate\Database\Eloquent\Factories\Factory<\Illuminate\Database\Eloquent\Model>> $factoryName */
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
     * @param  class-string<\Illuminate\Database\Eloquent\Factories\Factory<\Illuminate\Database\Eloquent\Model>>  $factoryName
     * @return class-string<\Illuminate\Database\Eloquent\Model>
     */
    public static function modelNameFor(string $factoryName): string
    {
        $suffix = str_contains($factoryName, '\\Database\\Factories\\')
            ? substr($factoryName, strpos($factoryName, '\\Database\\Factories\\') + strlen('\\Database\\Factories\\'))
            : class_basename($factoryName);

        $suffix = preg_replace('/Factory$/', '', $suffix) ?? $suffix;

        $hostModel = rtrim((string) app()->getNamespace(), '\\').'\\Models\\'.$suffix;

        if (class_exists($hostModel)) {
            /** @var class-string<\Illuminate\Database\Eloquent\Model> $hostModel */
            return $hostModel;
        }

        /** @var class-string<\Illuminate\Database\Eloquent\Model> $packageModel */
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
            $tenantId = tenancy()->initialized && tenancy()->tenant instanceof Tenant
                ? (string) tenancy()->tenant->getTenantKey()
                : null;

            $user = Auth::user();

            return [
                'tenant_id' => $tenantId,
                'guard' => Auth::getDefaultDriver(),
                'user_global_id' => $user instanceof NumerosisUser ? $user->global_id : null,
            ];
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
     * Config-first: `numerosis.models.{$model}` lets a host redirect every
     * package call site touching that model to its own subclass (extra
     * columns, relationships, methods) by setting one config key, instead of
     * editing each call site by hand. Unset (the default), this returns
     * `$model` unchanged — see D8 in .claude/plans/package-extraction.md for
     * why the previous design (abstract package models + 108 hand-written
     * wrapper calls with a by-convention host-namespace fallback) was
     * dropped: it produced six distinct instantiation-by-proxy bugs across
     * sessions and never actually read config despite being justified by it.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<TModel>  $model
     * @return class-string<TModel>
     */
    public static function model(string $model): string
    {
        $override = Config::get("numerosis.models.{$model}");

        /** @var class-string<TModel> */
        return is_string($override) ? $override : $model;
    }
}
