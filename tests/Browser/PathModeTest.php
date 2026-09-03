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

uses(PathModeTestCase::class, RefreshDatabase::class);
/**
 * Path mode's HTTP round trip — the one thing about it no console test could
 * reach, and the reason this browser suite exists.
 *
 * `.ai/rules/identification-modes.md` records the mechanism: stancl's
 * `PathTenantResolver` calls `$route->forgetParameter('tenant')` in both
 * `resolveWithoutCache()` and `resolved()`, so anything ordered after this
 * package's identification middleware finds no `tenant` parameter left —
 * tenancy is initialized, the parameter is gone, and the route that needs it
 * to regenerate its own URL throws several layers from the cause.
 * `Nvade\Numerosis\Resolvers\PreservingPathTenantResolver` overrides both
 * methods to leave the parameter alone.
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

    // The tenant landing page, not a 404 — reaching it at all is what
    // exercises the resolver, since the route only matches once the path
    // segment has been consumed as a tenant.
    $page->assertSee(Config::string('app.name'));

    expect((string) $page->content())->not->toContain('Server Error');
});

it('renders the tenant landing page under its path prefix for a signed-in user', function (): void {
    $tenant = bootTenantWithSignedInUser();

    $content = (string) visit("/{$tenant->id}")->content();

    expect($content)->toContain(Config::string('app.name'));
    expect(str_contains($content, 'Server Error'))->toBeFalse();
});
