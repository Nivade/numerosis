<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Support;

use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Nvade\Numerosis\Http\Middleware\CheckInvitationStatus;
use Nvade\Numerosis\Http\Middleware\EnsureSessionMatchesTenant;
use Nvade\Numerosis\Providers\TenancyServiceProvider;
use ReflectionClass;
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
     * which is correct for a package model the host has published a concrete
     * stub for (`App\Models\Central\Tenant`), but wrong for the many models
     * that live entirely inside the package and never get a stub (`Membership`,
     * `Role`, `SocialiteLogin`, …) — those still need
     * `Nvade\Numerosis\Models\…`.
     *
     * Resolution order: if the package's own class under that `Models\`
     * suffix exists and is not `abstract`, use it (the no-stub case).
     * Abstract means a stub is required — fall back to the host's own model
     * namespace, same two call sites `factoryNameFor()`'s docblock
     * describes (`NumerosisServiceProvider` for real apps, the package's
     * own Workbench models for its test suite).
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

        $packageModel = 'Nvade\\Numerosis\\Models\\'.$suffix;

        if (class_exists($packageModel) && ! (new ReflectionClass($packageModel))->isAbstract()) {
            /** @var class-string<\Illuminate\Database\Eloquent\Model> $packageModel */
            return $packageModel;
        }

        /** @var class-string<\Illuminate\Database\Eloquent\Model> $hostModel */
        $hostModel = rtrim((string) app()->getNamespace(), '\\').'\\Models\\'.$suffix;

        return $hostModel;
    }

    /**
     * Resolve the concrete class for one of the package's abstract models
     * (`Tenant`, `Domain`, `CentralUser`, `Subscription`, `PaymentPlan`,
     * `PendingTenantProvision`, `Tenant\Invitation`, `Tenant\Module`,
     * `Tenant\User` — see .claude/plans/package-extraction.md Phase 4.4).
     *
     * Every one of these is `abstract`, so any call written literally as
     * `Tenant::query()`/`Tenant::find(...)`/etc — anywhere in this package's
     * own source — resolves `new static` inside Eloquent's base methods to
     * the abstract class itself and throws `Cannot instantiate abstract
     * class`. This is not a test-only artifact: it throws in a real request
     * too, the moment that line runs. Package code must call
     * `Numerosis::model(Tenant::class)::query()` (or store the resolved
     * class-string in a local first) instead of the literal class name for
     * any static Eloquent call.
     *
     * Same host-stub-namespace convention `modelNameFor()`'s fallback uses:
     * `Nvade\Numerosis\Models\Central\Tenant` → `App\Models\Central\Tenant`.
     * A concrete (non-abstract) argument passes through unchanged, so this
     * is safe to call unconditionally even on a model that turns out not to
     * need a stub.
     *
     * Generic over the model type: PHPStan resolves the return type to
     * `class-string<TModel>` for whichever concrete subclass was passed in
     * (e.g. `class-string<Tenant>`, not the erased `class-string<Model>`),
     * so a call site doing `Numerosis::model(Tenant::class)::find(...)`
     * keeps Eloquent's normal return-type narrowing instead of collapsing
     * every downstream property/method access to the base `Model` type. The
     * host-stub branch below returns a *different* class than the generic
     * parameter (`App\Models\Central\Tenant` extends, but is not,
     * `Nvade\Numerosis\Models\Central\Tenant`) — PHPStan cannot express
     * "TModel's host subclass" so this is accepted as slightly optimistic:
     * true at runtime because the stub is declared to extend TModel, which
     * is all any caller relies on.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<TModel>  $abstractModel
     * @return class-string<TModel>
     */
    public static function model(string $abstractModel): string
    {
        if (! (new ReflectionClass($abstractModel))->isAbstract()) {
            return $abstractModel;
        }

        $suffix = str_contains($abstractModel, '\\Models\\')
            ? substr($abstractModel, strpos($abstractModel, '\\Models\\') + strlen('\\Models\\'))
            : class_basename($abstractModel);

        /** @var class-string<TModel> $hostModel */
        $hostModel = rtrim((string) app()->getNamespace(), '\\').'\\Models\\'.$suffix;

        return $hostModel;
    }
}
