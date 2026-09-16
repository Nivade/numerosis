<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Boot;

use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Concerns\Boot\RegistersOnce;
use Nvade\Numerosis\Enums\MiddlewareAlias;
use Nvade\Numerosis\Http\Middleware\Authenticate;
use Nvade\Numerosis\Http\Middleware\EnsureSessionMatchesTenant;
use Nvade\Numerosis\Http\Middleware\EnsureTenantMembership;
use Nvade\Numerosis\Http\Middleware\EnsureTenantSubscriptionActive;
use Nvade\Numerosis\Http\Middleware\InitializeTenancy;
use Nvade\Numerosis\Http\Middleware\RequirePasswordIfSet;
use Nvade\Numerosis\Http\Middleware\TenantRouteGuard;

/**
 * The one definition of the package's aliases and groups, read by both
 * {@see \Nvade\Numerosis\Numerosis::middleware()} and
 * `NumerosisServiceProvider::registerMiddleware()`. An alias present in only
 * one of the two works in this repo and fails in a host, or the reverse.
 */
final class MiddlewareRegistrar
{
    use RegistersOnce;

    /**
     * Pure class-string literals only, with no config or container read: this
     * runs from a host's `bootstrap/app.php` before `RegisterFacades`, so
     * anything touching `Config`/`Facade` fatals on a real boot.
     * `tenancy.identification` and `tenancy.route` therefore alias to
     * delegating middleware that picks the real class at request time.
     *
     * @return array<string, string>
     */
    public static function aliases(): array
    {
        return [
            // Laravel's `auth`, plus the central-to-tenant session promotion.
            // It takes its own alias because `auth` is the host's, and every
            // central route using that one must keep Laravel's behaviour.
            MiddlewareAlias::TenancyAuth->value => Authenticate::class,

            MiddlewareAlias::PasswordConfirmIfSet->value => RequirePasswordIfSet::class,

            // Apply the suspension gate per route group. It redirects to
            // `tenant.suspended`, itself a tenant route, so putting it on the
            // `tenant` group as a whole loops.
            MiddlewareAlias::TenancySubscription->value => EnsureTenantSubscriptionActive::class,

            MiddlewareAlias::TenancyIdentification->value => InitializeTenancy::class,
            MiddlewareAlias::TenancyRoute->value => TenantRouteGuard::class,
            MiddlewareAlias::TenancySession->value => EnsureSessionMatchesTenant::class,

            // Off the `tenant` group for the same reason as the subscription
            // gate: it redirects to a central route and reads the tenant
            // guard, so it belongs on the authenticated tenant routes only.
            MiddlewareAlias::TenancyMembership->value => EnsureTenantMembership::class,
        ];
    }

    /**
     * Subject to the same literals-only rule as {@see self::aliases()}.
     *
     * @return array<string, list<string>>
     */
    public static function groups(): array
    {
        return [
            'tenant' => [
                'web',
                MiddlewareAlias::TenancyIdentification->value,
                MiddlewareAlias::TenancyRoute->value,
                MiddlewareAlias::TenancySession->value,
            ],
            'universal' => [],
        ];
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

    public static function apply(Middleware $middleware): void
    {
        self::$registered = true;

        $middleware->alias(self::aliases());

        foreach (self::groups() as $name => $stack) {
            $middleware->group($name, $stack);
        }

        // No trustProxies() call, deliberately: trusting a proxy the host did
        // not name makes every IP-keyed rate limiter and audit log spoofable
        // by anyone who can reach the app directly.

        // Laravel's default trusts config('app.url') and all its subdomains,
        // which already covers the central domain plus every tenant subdomain.
        $middleware->trustHosts();

        // No `redirectGuestsTo()` here: `registerGuestRedirect()` calls
        // `Authenticate::redirectUsing()` on every boot, which overrides what
        // `ApplicationBuilder::withMiddleware()` set before this callback ran.
    }

    /**
     * The proxy IP(s)/CIDR — or the literal `'*'` — read from
     * `numerosis.trusted_proxies`. Only meaningful once config exists, so it
     * is never called from {@see self::apply()} itself; the un-wired fallback
     * in `NumerosisServiceProvider::registerMiddleware()` is the one caller,
     * and it runs from `packageBooted()`, well after config is loaded.
     *
     * @return list<string>|string
     */
    public static function trustedProxies(): array|string
    {
        $configured = Config::get('numerosis.trusted_proxies');

        if ($configured === '*') {
            return '*';
        }

        return is_array($configured)
            ? array_values(array_filter($configured, is_string(...)))
            : [];
    }
}
