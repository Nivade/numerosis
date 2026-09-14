<?php

declare(strict_types=1);
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Actions\Tenancy\CreateTenantDomain;
use Nvade\Numerosis\Tests\Support\TestTenant;
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
    $tenant = TestTenant::provisioned();
    CreateTenantDomain::run($tenant, $tenant->id);

    signInTenantUser($tenant);

    Playwright::setHost("{$tenant->id}.numerosistest.test");

    expectTenantLandingPage('/');
});

it('serves the tenant landing page on the subdomain for an unauthenticated visitor', function (): void {
    $tenant = TestTenant::provisioned();
    CreateTenantDomain::run($tenant, $tenant->id);

    Playwright::setHost("{$tenant->id}.numerosistest.test");

    expectTenantLandingPage('/');
});
