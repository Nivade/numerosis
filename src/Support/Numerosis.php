<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Support;

use Nvade\Numerosis\Http\Middleware\CheckInvitationStatus;
use Nvade\Numerosis\Http\Middleware\EnsureSessionMatchesTenant;
use Nvade\Numerosis\Providers\TenancyServiceProvider;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
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
        foreach (Config::array('tenancy.central_domains') as $domain) {
            if (! is_string($domain)) {
                continue;
            }

            Route::middleware('web')
                ->domain($domain)
                ->group(base_path('routes/web.php'));
        }

        Route::middleware('tenant')->group(base_path('routes/tenant.php'));
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
}
