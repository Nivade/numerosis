<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Tenancy;

use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Nvade\Numerosis\Actions\Tenancy\CreateTenantDomain;
use Nvade\Numerosis\Enums\Tenancy\IdentificationMode;
use Nvade\Numerosis\Events\Tenancy\TenantDomainReserved;
use Nvade\Numerosis\Tests\TestCase;

class CreateTenantDomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_dispatches_tenant_domain_reserved_when_a_domain_row_is_created(): void
    {
        Event::fake([TenantDomainReserved::class]);
        Config::set('numerosis.tenancy.identification.mode', IdentificationMode::Subdomain->value);

        $tenant = Tenant::factory()->create();

        $domain = CreateTenantDomain::run($tenant, 'acme');

        $this->assertNotNull($domain);

        Event::assertDispatched(fn (TenantDomainReserved $e): bool => $e->tenantId === $tenant->id
            && $e->domain === $domain->domain
            && $e->mode === IdentificationMode::Subdomain);
    }

    /**
     * Idempotent: re-running for a domain that already exists must not fire
     * the event a second time.
     */
    public function test_it_does_not_dispatch_again_for_an_existing_domain_row(): void
    {
        Config::set('numerosis.tenancy.identification.mode', IdentificationMode::Subdomain->value);
        $tenant = Tenant::factory()->create();

        CreateTenantDomain::run($tenant, 'acme');

        Event::fake([TenantDomainReserved::class]);

        CreateTenantDomain::run($tenant, 'acme');

        Event::assertNotDispatched(TenantDomainReserved::class);
    }

    public function test_it_dispatches_nothing_under_path_mode(): void
    {
        Event::fake([TenantDomainReserved::class]);
        Config::set('numerosis.tenancy.identification.mode', IdentificationMode::Path->value);

        $tenant = Tenant::factory()->create();

        CreateTenantDomain::run($tenant, 'acme');

        Event::assertNotDispatched(TenantDomainReserved::class);
    }
}
