<?php

declare(strict_types=1);

use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Data\Tenancy\CustomDomainContribution;
use Nvade\Numerosis\Tests\Browser\CustomDomainModeTestCase;
use Nvade\Numerosis\Tests\Support\TestTenant;
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
    $tenant = TestTenant::provisioned(contributions: [new CustomDomainContribution('app.acmetest.test')]);

    signInTenantUser($tenant);

    Playwright::setHost('app.acmetest.test');

    expectTenantLandingPage('/');
});

it('serves the tenant landing page on the custom domain for an unauthenticated visitor', function (): void {
    $tenant = TestTenant::provisioned(contributions: [new CustomDomainContribution('app.acmetest.test')]);

    Playwright::setHost('app.acmetest.test');

    expectTenantLandingPage('/');
});
