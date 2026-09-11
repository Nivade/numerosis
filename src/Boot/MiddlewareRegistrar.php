<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Boot;

use Closure;
use Illuminate\Foundation\Configuration\Middleware;
use Nvade\Numerosis\Http\Middleware\Authenticate;
use Nvade\Numerosis\Http\Middleware\EnsureSessionMatchesTenant;
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
    /** Replaces this class's registration entirely; receives the `Application`. */
    public static ?Closure $registerCallback = null;

    private static bool $registered = false;

    /**
     * This array must stay pure class-string literals with no config or
     * container read: it is reached from a host's `bootstrap/app.php`, before
     * `RegisterFacades`, so anything evaluated here that touches
     * `Config`/`Facade` fatals on a real boot. `tenancy.identification` and
     * `tenancy.route` therefore alias to delegating middleware that picks the
     * real class at request time.
     *
     * @return array<string, string>
     */
    public static function aliases(): array
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
     * Subject to the same literals-only rule as {@see self::aliases()}.
     *
     * @return array<string, list<string>>
     */
    public static function groups(): array
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

        $middleware->trustProxies('*');

        // Laravel's default trusts config('app.url') and all its subdomains,
        // which already covers the central domain plus every tenant subdomain.
        $middleware->trustHosts();

        // No `redirectGuestsTo()` here: `registerGuestRedirect()` calls
        // `Authenticate::redirectUsing()` on every boot, which overrides what
        // `ApplicationBuilder::withMiddleware()` set before this callback ran.
    }

    /**
     * Whether {@see self::apply()} has run. `NumerosisServiceProvider` stands
     * down when it has, so trust configuration and alias swaps made after it
     * in the same `withMiddleware()` closure survive.
     */
    public static function registered(): bool
    {
        return self::$registered;
    }

    /**
     * For tests only. The flag is a process-lifetime static, so one test
     * calling {@see self::apply()} would otherwise stand the service provider
     * down for every test after it in the same worker.
     */
    public static function resetForTesting(): void
    {
        self::$registered = false;
    }
}
