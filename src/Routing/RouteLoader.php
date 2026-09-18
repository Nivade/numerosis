<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Routing;

use Closure;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features as FortifyFeatures;
use Laravel\Fortify\Fortify as FortifyFacade;
use Laravel\Fortify\Http\Controllers\TwoFactorAuthenticatedSessionController;
use Laravel\Fortify\RoutePath;
use Nvade\Numerosis\Concerns\Boot\RegistersOnce;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Enums\Tenancy\IdentificationMode;
use Nvade\Numerosis\Features\Auth\OneTimePasswordFeature;
use Nvade\Numerosis\Http\Controllers\Auth\OneTimePasswordChallengeController;
use ReflectionClass;
use Stancl\Tenancy\Resolvers\PathTenantResolver;

/**
 * Builds the package's route groups. Reached through
 * {@see \Nvade\Numerosis\Numerosis::routes()}, which is what a host calls.
 */
final class RouteLoader
{
    use RegistersOnce;

    private static bool $authRoutesEnabled = true;

    /**
     * Central routes bind to each hostname in `tenancy.central_domains`
     * separately, because tenant identification never runs for those domains.
     * `routes/web.php`, `routes/tenant.php` and `routes/api.php` in the host's
     * own base path are loaded alongside the package's, each optional.
     */
    public static function load(bool $withAuth = true, string $apiPrefix = 'api'): void
    {
        self::$registered = true;
        self::$authRoutesEnabled = $withAuth;

        if (self::$registerCallback instanceof Closure) {
            (self::$registerCallback)(app());

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
        // would have built had `using:` left it reachable. The package's own
        // API routes go first, so a host route on the same URI replaces them.
        Route::prefix($apiPrefix)->group($routes.'/api.php');

        if (is_file($api = base_path('routes/api.php'))) {
            Route::middleware('api')->prefix($apiPrefix)->group($api);
        }
    }

    /**
     * Whether `routes/web.php` should require `routes/auth.php`. Read there,
     * set by {@see self::load()}'s `$withAuth` argument.
     */
    public static function authRoutesEnabled(): bool
    {
        return self::$authRoutesEnabled;
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

        $tenantGroup = $guard !== Context::Central->guard();
        $twoFactor = FortifyFeatures::enabled(FortifyFeatures::twoFactorAuthentication());

        if ($tenantGroup && $twoFactor) {
            Config::set('fortify.features', array_values(array_filter(
                Config::array('fortify.features'),
                static fn (mixed $feature): bool => $feature !== FortifyFeatures::twoFactorAuthentication(),
            )));
        }

        try {
            require self::fortifyRoutesPath();

            if ($tenantGroup && $twoFactor) {
                self::loadTwoFactorChallengeRoutes($guard);
            }
        } finally {
            Config::set('fortify', $original);
        }
    }

    /**
     * The challenge alone, in the tenant group. Enrolment is a central screen
     * because the account every login checks its credentials against is the
     * central one, so a secret on a tenant user would never be challenged.
     */
    private static function loadTwoFactorChallengeRoutes(string $guard): void
    {
        $path = RoutePath::for('two-factor.login', '/two-factor-challenge');
        $limiter = Config::get('fortify.limiters.two-factor');

        Route::get($path, [TwoFactorAuthenticatedSessionController::class, 'create'])
            ->middleware('guest:'.$guard)
            ->name('two-factor.login');

        Route::post($path, [TwoFactorAuthenticatedSessionController::class, 'store'])
            ->middleware(array_filter([
                'guest:'.$guard,
                is_string($limiter) ? 'throttle:'.$limiter : null,
            ]))
            ->name('two-factor.login.store');
    }

    private static function fortifyRoutesPath(): string
    {
        $reflection = new ReflectionClass(FortifyFacade::class);

        return dirname((string) $reflection->getFileName(), 2).'/routes/routes.php';
    }

    /**
     * Paths go through `RoutePath::for()`, so `config('fortify.paths')`
     * overrides them like every neighbouring auth URL. The POST leg carries
     * its own limiter because its request has no `email` field to key on.
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
}
