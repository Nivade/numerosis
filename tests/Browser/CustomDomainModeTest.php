<?php

declare(strict_types=1);

use App\Models\Central\Tenant;
use App\Models\Tenant\User as TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Actions\Tenancy\CreateTenantDomain;
use Nvade\Numerosis\Tests\Browser\CustomDomainModeTestCase;
use Pest\Browser\Playwright\Playwright;

uses(CustomDomainModeTestCase::class, RefreshDatabase::class);

/**
 * Custom-domain mode's HTTP round trip — the second of the two non-path legs
 * `.claude/plans/numerosis-consolidation.md` left open after `PathModeTest`.
 *
 * `.claude/rules/identification-modes.md` records the mechanism this
 * exercises: `NumerosisTenantPlugin::tenantDomainPattern()` returns the
 * literal `{tenant}` for this mode (not `{tenant}.<apex>`), so Filament's
 * `{tenant}` route parameter *is* the whole custom domain, and
 * `Tenant::resolveRouteBinding()` looks it up by `domains.domain` rather than
 * `tenants.id`. `CreateTenantDomain::run()` is what writes that row with the
 * custom domain verbatim rather than a derived subdomain.
 */
it('renders the authenticated tenant panel under its custom domain', function (): void {
    $tenant = Tenant::factory()->create();
    CreateTenantDomain::run($tenant, $tenant->id, 'app.acmetest.test');

    $tenant->run(function (): void {
        $user = TenantUser::factory()->create();

        Auth::guard(Config::string('numerosis.auth.guards.tenant'))->login($user);
    });

    Playwright::setHost('app.acmetest.test');

    $content = (string) visit('/')->content();

    expect($content)->toContain('Dashboard');
    expect($content)->toContain((string) $tenant->name);
    expect(str_contains($content, 'Server Error'))->toBeFalse();
});

it('serves the login page on the custom domain for an unauthenticated visitor', function (): void {
    $tenant = Tenant::factory()->create();
    CreateTenantDomain::run($tenant, $tenant->id, 'app.acmetest.test');

    Playwright::setHost('app.acmetest.test');

    $page = visit('/');

    $page->assertSee('Log in');

    expect((string) $page->content())->not->toContain('Server Error');
});
