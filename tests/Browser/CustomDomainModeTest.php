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
 * `.claude/plans/archive/numerosis-consolidation.md` left open after `PathModeTest`.
 *
 * `.ai/rules/identification-modes.md` records the mechanism this exercises:
 * the tenant is identified from the *whole* host rather than a subdomain
 * label, and `CreateTenantDomain::run()` is what writes that `domains` row
 * with the custom domain verbatim rather than a derived subdomain.
 */
it('renders the tenant landing page under its custom domain for a signed-in user', function (): void {
    $tenant = Tenant::factory()->create();
    CreateTenantDomain::run($tenant, $tenant->id, 'app.acmetest.test');

    $tenant->run(function (): void {
        $user = TenantUser::factory()->create();

        Auth::guard(Config::string('numerosis.auth.guards.tenant'))->login($user);
    });

    Playwright::setHost('app.acmetest.test');

    $content = (string) visit('/')->content();

    expect($content)->toContain(Config::string('app.name'));
    expect(str_contains($content, 'Server Error'))->toBeFalse();
});

it('serves the tenant landing page on the custom domain for an unauthenticated visitor', function (): void {
    $tenant = Tenant::factory()->create();
    CreateTenantDomain::run($tenant, $tenant->id, 'app.acmetest.test');

    Playwright::setHost('app.acmetest.test');

    $page = visit('/');

    $page->assertSee(Config::string('app.name'));

    expect((string) $page->content())->not->toContain('Server Error');
});
