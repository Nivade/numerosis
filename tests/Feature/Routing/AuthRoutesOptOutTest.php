<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Routing;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Nvade\Numerosis\Numerosis;
use Nvade\Numerosis\Tests\TestCase;
use Override;

/**
 * `login`, `register`, `logout` and `verification.verify` are registered
 * behind no feature flag at all, so a host keeping its own auth system —
 * Fortify, Breeze, anything — used to get a silent route-name collision on
 * those four: Laravel's router keeps whichever was registered last, making
 * "which system serves /login" a function of provider order.
 * `Numerosis::routes(withAuth: false)` is the opt-out; everything else in
 * `routes/web.php` still has to register, which is the half worth testing —
 * the previous answer was "skip Numerosis::routes() entirely and hand-roll a
 * replacement", i.e. duplicate the billing and checkout wiring.
 *
 * Since Phase 4 of `.claude/plans/archive/humming-nibbling-flame.md` the flag covers
 * Fortify's whole route file, not just the handful of names core declared
 * itself: `Numerosis::routes(withAuth: false)` skips the per-group `require`
 * of `vendor/laravel/fortify/routes/routes.php` entirely. That is a much
 * wider surface than the four names this test was written for, so every name
 * the file registers under the enabled features is asserted absent — a
 * partial opt-out (say, `login` gone but `password.email` still posting into
 * Fortify) is the failure worth catching.
 */
class AuthRoutesOptOutTest extends TestCase
{
    #[Override]
    protected function defineRoutes($router): void
    {
        Numerosis::routes(withAuth: false);
    }

    public function test_it_registers_none_of_the_auth_route_names(): void
    {
        $names = [
            'login',
            'login.store',
            'logout',
            'register',
            'register.store',
            'password.request',
            'password.reset',
            'password.email',
            'password.update',
            'password.confirm',
            'verification.notice',
            'verification.verify',
            'verification.send',
        ];

        foreach ($names as $name) {
            $this->assertFalse(Route::has($name), "Route [{$name}] was registered despite withAuth: false.");
        }
    }

    public function test_it_still_registers_the_rest_of_the_central_routes(): void
    {
        foreach (['home', 'billing.webhook', 'checkout.subscription', 'checkout.resume', 'tenants.create'] as $name) {
            $this->assertTrue(Route::has($name), "Route [{$name}] is not part of the auth opt-out and should still be registered.");
        }
    }

    /**
     * The flag is a static on `Numerosis`, so it has to reset for the next
     * test — every other test in this suite calls `Numerosis::routes()` with
     * no argument from `TestCase::defineRoutes()`, which is what restores it,
     * and each of those tests then resolving `login` is the standing proof
     * that the default path is unaffected. (Re-registering the route files
     * mid-test is not: the router's name list is already built by then.)
     */
    public function test_the_default_restores_auth_route_registration(): void
    {
        $this->assertFalse(Numerosis::authRoutesEnabled());

        Numerosis::routes();

        $this->assertTrue(Numerosis::authRoutesEnabled());
    }

    /**
     * `withRouting(using: ...)` is invoked through `$this->app->call()`, which
     * resolves an unbound primitive from its default rather than passing the
     * router — so `Numerosis::routes(...)` as a first-class callable keeps auth
     * routes, and only an explicit closure turns them off. Asserted because a
     * container that ever started injecting there would silently disable every
     * host's auth routes.
     */
    public function test_the_first_class_callable_form_defaults_to_including_them(): void
    {
        Numerosis::routes(withAuth: false);

        $this->assertFalse(Numerosis::authRoutesEnabled());

        $this->app?->call(Numerosis::routes(...));

        $this->assertTrue(Numerosis::authRoutesEnabled());
        $this->assertInstanceOf(Router::class, $this->app?->make(Router::class));
    }
}
