<?php

declare(strict_types=1);

use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
 * `Nvade\Numerosis\Services\Tenancy\PreservingPathTenantResolver` overrides both
 * methods to leave the parameter alone.
 *
 * Until now that necessity was source-derived and untested.
 */
it('identifies a tenant from the path on an unauthenticated request', function (): void {
    $tenant = Tenant::factory()->create();

    Playwright::setHost('central.numerosistest.test');

    // The tenant landing page, not a 404 — reaching it at all is what
    // exercises the resolver, since the route only matches once the path
    // segment has been consumed as a tenant.
    expectTenantLandingPage("/{$tenant->id}");
});

it('renders the tenant landing page under its path prefix for a signed-in user', function (): void {
    $tenant = Tenant::factory()->create();

    signInTenantUser($tenant);

    Playwright::setHost('central.numerosistest.test');

    expectTenantLandingPage("/{$tenant->id}");
});
