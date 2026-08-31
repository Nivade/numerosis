<?php

declare(strict_types=1);

use App\Models\Central\Tenant;
use App\Models\Tenant\User as TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Models\Central\Tenant as TenantModel;
use Nvade\Numerosis\Tests\Browser\PathModeTestCase;
use Pest\Browser\Playwright\Playwright;
use Stancl\Tenancy\Resolvers\PathTenantResolver;

uses(PathModeTestCase::class, RefreshDatabase::class);
/**
 * Path mode's HTTP round trip — the one thing about it no console test could
 * reach, and the reason this browser suite exists.
 *
 * `.claude/rules/identification-modes.md` records the mechanism:
 * `PathTenantResolver` calls `$route->forgetParameter('tenant')` in both
 * `resolveWithoutCache()` and `resolved()`, and this package's identification
 * middleware is forced to highest priority, so by the time Filament's own
 * `IdentifyTenant` runs there is no `tenant` parameter left. That middleware
 * early-returns on exactly that condition, stancl's tenancy is initialized
 * anyway, and `Filament::getTenant()` is quietly null — nothing errors where
 * the mistake is. `Nvade\Numerosis\Resolvers\PreservingPathTenantResolver`
 * overrides both methods to leave the parameter alone.
 *
 * Until now that necessity was source-derived and untested.
 *
 * Returns the package's own Tenant rather than the host subclass the factory
 * builds at runtime: Larastan resolves `Tenant::factory()->create()` through
 * the factory's generic, which names the package class, so declaring the
 * subclass here is a type error it is right about.
 */
function bootTenantWithSignedInUser(): TenantModel
{
    $tenant = Tenant::factory()->create();

    $tenant->run(function (): void {
        $user = TenantUser::factory()->create();

        // Deliberately *not* actingAsTenantPanelUser(): that helper also calls
        // Filament::setTenant() by hand, which is exactly the state a real
        // request has to establish for itself and the thing under test here.
        //
        // Auth::login() rather than the test's own actingAs(): identical
        // effect on the guard, and it needs no reference to the TestCase —
        // PHPStan types `$this` inside a Pest closure as
        // Pest\PendingCalls\TestCall, so passing it to a typed parameter is
        // an error it cannot see through.
        Auth::guard(Config::string('numerosis.auth.guards.tenant'))->login($user);
    });

    Playwright::setHost('central.numerosistest.test');

    return $tenant;
}

it('identifies a tenant from the path on an unauthenticated request', function (): void {
    $tenant = Tenant::factory()->create();

    Playwright::setHost('central.numerosistest.test');

    $page = visit("/{$tenant->id}");

    // The panel's own login page. Every URL on it is a tenantAdmin route, and
    // generating one without the {tenant} parameter throws rather than
    // degrading, so reaching this at all exercises the resolver.
    $page->assertSee('Log in');

    // ...and it is this tenant, not merely a page that rendered. The id
    // reaches the markup only once stancl's tenancy is initialized.
    expect((string) $page->content())->toContain($tenant->id);
});

it('renders the authenticated tenant panel under its path prefix', function (): void {
    $tenant = bootTenantWithSignedInUser();

    $content = (string) visit("/{$tenant->id}")->content();

    expect($content)->toContain('Dashboard');

    // Filament's own layout renders the tenant's name, which it can only reach
    // through Filament::getTenant() — the value stancl's resolver destroys and
    // this package's subclass preserves.
    expect($content)->toContain((string) $tenant->name);

    expect(str_contains($content, 'Server Error'))->toBeFalse();
});

/**
 * The negative control, and the whole point of the test above: it must be
 * possible for this to break.
 *
 * Rebinding is enough because `Stancl\Tenancy\Middleware\InitializeTenancyByPath`
 * takes its resolver through constructor injection, so the container is asked
 * for one per request — `TenancyServiceProvider` binds the preserving subclass
 * with `bind()`, not `singleton()`, and nothing resolves it before the visit.
 *
 * Measured failure with stancl's own resolver in place:
 * `Filament\Panel::getTenantBillingUrl(): Argument #1 ($tenant) must be of
 * type Illuminate\Database\Eloquent\Model, null given` — i.e. exactly the null
 * tenant the override exists to prevent, surfacing several layers from the
 * cause, in a view, as a 500.
 */
it('breaks without the preserving resolver, which is why the override exists', function (): void {
    $tenant = bootTenantWithSignedInUser();

    app()->bind(PathTenantResolver::class, PathTenantResolver::class);

    $content = (string) visit("/{$tenant->id}")->content();

    expect($content)->toContain('Server Error');
    expect(str_contains($content, 'Dashboard'))->toBeFalse();
});
