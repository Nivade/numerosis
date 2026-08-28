<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Support;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Nvade\Numerosis\Support\Numerosis;
use Nvade\Numerosis\Tests\TestCase;
use Override;

/**
 * `routes/auth.php` registers `login`, `register`, `logout` and
 * `verification.verify` behind no feature flag at all (only the OAuth and
 * password-reset routes inside it are gated), so a host keeping its own auth
 * system — Fortify, Breeze, anything — used to get a silent route-name
 * collision on those four: Laravel's router keeps whichever was registered
 * last, making "which system serves /login" a function of provider order.
 * `Numerosis::routes(withAuth: false)` is the opt-out; everything else in
 * `routes/web.php` still has to register, which is the half worth testing —
 * the previous answer was "skip Numerosis::routes() entirely and hand-roll a
 * replacement", i.e. duplicate the billing and checkout wiring.
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
        foreach (['login', 'register', 'logout', 'verification.verify'] as $name) {
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
