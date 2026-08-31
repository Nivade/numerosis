<?php

declare(strict_types=1);

use App\Models\Central\Tenant;
use App\Models\Tenant\User as TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Actions\Tenancy\CreateTenantDomain;
use Nvade\Numerosis\Tests\TestCase;
use Pest\Browser\Playwright\Playwright;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Subdomain is the default identification mode, so this needs no
 * mode-switching base class the way `PathModeTest` does (`PathModeTestCase`
 * exists specifically because the mode has to be picked before boot).
 *
 * `.claude/rules/filament-tenancy.md` records that `PHP_SAPI` stays `cli`
 * inside a browser request, so `shouldRegisterPanel()`'s console exemption
 * still applies — a request to `central.numerosistest.test` here would still
 * see the tenant panel registered and could resolve the `{tenant}` wildcard
 * regardless of what's configured. That question stays out of reach; this
 * test only drives a real tenant subdomain, which is unaffected because it
 * was never a central-domain request in the first place.
 */
it('renders the authenticated tenant panel under its subdomain', function (): void {
    $tenant = Tenant::factory()->create();
    CreateTenantDomain::run($tenant, $tenant->id);

    $tenant->run(function (): void {
        $user = TenantUser::factory()->create();

        Auth::guard(Config::string('numerosis.auth.guards.tenant'))->login($user);
    });

    Playwright::setHost("{$tenant->id}.numerosistest.test");

    $content = (string) visit('/')->content();

    expect($content)->toContain('Dashboard');
    expect($content)->toContain((string) $tenant->name);
    expect(str_contains($content, 'Server Error'))->toBeFalse();
});

it('serves the login page on the tenant subdomain for an unauthenticated visitor', function (): void {
    $tenant = Tenant::factory()->create();
    CreateTenantDomain::run($tenant, $tenant->id);

    Playwright::setHost("{$tenant->id}.numerosistest.test");

    $page = visit('/');

    $page->assertSee('Log in');

    expect((string) $page->content())->not->toContain('Server Error');
});
