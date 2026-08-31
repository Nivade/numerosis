<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Support;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Nvade\Numerosis\Tests\TestCase;

/**
 * The counterpart to `AuthRoutesOptOutTest`: that one proves the satellite
 * honours the opt-out, this one proves it registers at all, and — the part
 * that is easy to get wrong and impossible to see from a 200 — that its
 * routes land *inside* core's own groups rather than beside them.
 *
 * `Numerosis::addCentralRoutes()` exists because the central group is not
 * reproducible from outside: it wraps `routes/web.php` in
 * `Route::middleware('web')->domain($domain)` once per configured central
 * domain. A satellite that registered its login route with a plain
 * `Route::get()` would answer on every tenant subdomain too, and nothing
 * would fail — the route resolves, it is just bound to the wrong hosts.
 */
class SatelliteRouteContributionTest extends TestCase
{
    public function test_the_auth_ui_package_contributes_its_central_routes(): void
    {
        foreach (['login', 'register', 'password.request', 'password.reset'] as $name) {
            $this->assertTrue(Route::has($name), "Route [{$name}] is missing — nvade/numerosis-auth-ui is not contributing its central routes.");
        }
    }

    public function test_those_routes_are_bound_to_the_central_domain_and_the_web_group(): void
    {
        /** @var list<string> $central */
        $central = Config::array('tenancy.central_domains');

        $route = Route::getRoutes()->getByName('login');

        $this->assertNotNull($route);
        $this->assertContains(
            $route->getDomain(),
            $central,
            'The login route is not bound to a central domain, so it would also answer on every tenant subdomain.'
        );
        $this->assertContains('web', $route->gatherMiddleware());
    }

    public function test_the_auth_ui_package_contributes_its_tenant_routes(): void
    {
        foreach (['verification.notice', 'password.confirm'] as $name) {
            $this->assertTrue(Route::has($name), "Route [{$name}] is missing — nvade/numerosis-auth-ui is not contributing its tenant routes.");
        }

        $route = Route::getRoutes()->getByName('password.confirm');

        $this->assertNotNull($route);
        $this->assertContains('tenant', $route->gatherMiddleware());
    }

    /**
     * Filament's tenant panel serves this package's login page through
     * `numerosis.panels.tenant.login`, which core itself defaults to `null`.
     * If the satellite stopped filling it the panel would keep registering
     * cleanly and only fatal at the first `/login` on a tenant subdomain —
     * the late failure the seam was built to avoid.
     */
    public function test_the_auth_ui_package_fills_the_tenant_panel_login_seam(): void
    {
        $login = Config::get('numerosis.panels.tenant.login');

        $this->assertIsString($login);
        $this->assertTrue(class_exists($login), "Configured tenant-panel login component [{$login}] does not exist.");
    }
}
