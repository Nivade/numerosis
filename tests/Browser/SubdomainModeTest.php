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
 * Drives a real tenant subdomain end to end: the tenant group carries no
 * domain constraint of its own, so "the right group matched" is only
 * observable from the response.
 */
it('renders the tenant landing page under its subdomain for a signed-in user', function (): void {
    $tenant = Tenant::factory()->create();
    CreateTenantDomain::run($tenant, $tenant->id);

    $tenant->run(function (): void {
        $user = TenantUser::factory()->create();

        Auth::guard(Config::string('numerosis.auth.guards.tenant'))->login($user);
    });

    Playwright::setHost("{$tenant->id}.numerosistest.test");

    $content = (string) visit('/')->content();

    expect($content)->toContain(Config::string('app.name'));
    expect(str_contains($content, 'Server Error'))->toBeFalse();
});

it('serves the tenant landing page on the subdomain for an unauthenticated visitor', function (): void {
    $tenant = Tenant::factory()->create();
    CreateTenantDomain::run($tenant, $tenant->id);

    Playwright::setHost("{$tenant->id}.numerosistest.test");

    $page = visit('/');

    $page->assertSee(Config::string('app.name'));

    expect((string) $page->content())->not->toContain('Server Error');
});
