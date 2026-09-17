<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Boot;

use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Nvade\Numerosis\Concerns\Boot\RegistersOnce;
use Nvade\Numerosis\Enums\MiddlewareAlias;
use Nvade\Numerosis\Http\Middleware\Authenticate;
use Nvade\Numerosis\Http\Middleware\EnsureApiTokenIsUsable;
use Nvade\Numerosis\Http\Middleware\EnsureEntitlement;
use Nvade\Numerosis\Http\Middleware\EnsureSessionMatchesTenant;
use Nvade\Numerosis\Http\Middleware\EnsureTenantMembership;
use Nvade\Numerosis\Http\Middleware\EnsureTenantSubscriptionActive;
use Nvade\Numerosis\Http\Middleware\EnsureTwoFactorEnrolled;
use Nvade\Numerosis\Http\Middleware\GuardImpersonation;
use Nvade\Numerosis\Http\Middleware\InitializeTenancy;
use Nvade\Numerosis\Http\Middleware\RequirePasswordIfSet;
use Nvade\Numerosis\Http\Middleware\SecurityHeaders;
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

            MiddlewareAlias::TenancyTwoFactor->value => EnsureTwoFactorEnrolled::class,

            MiddlewareAlias::Impersonation->value => GuardImpersonation::class,

            // Takes the capability as a parameter: `numerosis.entitlement:custom-branding`.
            MiddlewareAlias::Entitlement->value => EnsureEntitlement::class,

            // Expiry and the egress allowlist, neither of which Sanctum checks.
            MiddlewareAlias::ApiToken->value => EnsureApiTokenIsUsable::class,

            // Sanctum's ability gate under this package's own alias: `abilities`
            // is a name the framework's default stack owns, and a host that
            // aliased it differently must keep its own meaning.
            MiddlewareAlias::ApiAbilities->value => CheckAbilities::class,
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

                // After the three above, never before: it reads the tenant
                // guard, which only exists once tenancy is initialized and
                // `EnsureSessionMatchesTenant` has dropped another tenant's
                // session state.
                AuthenticateSession::class,

                // On the group, not on the authenticated routes: an expired
                // impersonation has to end on whatever request arrives next,
                // including the tenant's own landing page.
                MiddlewareAlias::Impersonation->value,

                // On the group so a host's own product routes are gated too.
                // It redirects to a central route, so it cannot loop the way
                // the subscription gate would.
                MiddlewareAlias::TenancyTwoFactor->value,
            ],
            // The API's own stack: tenancy identification without a session,
            // since a token carries the caller and a cookie must not.
            'tenant-api' => [
                'api',
                MiddlewareAlias::TenancyIdentification->value,
                MiddlewareAlias::TenancyRoute->value,
            ],
            'universal' => [],
        ];
    }

    /**
     * Appended to a group the host owns, rather than replacing its stack the
     * way {@see self::groups()} does. The `tenant` group nests `web`, so one
     * entry there covers both sides of tenancy.
     *
     * @return array<string, list<string>>
     */
    public static function groupAppends(): array
    {
        return [
            'web' => [SecurityHeaders::class],
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

        foreach (self::groupAppends() as $name => $stack) {
            $middleware->appendToGroup($name, $stack);
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
