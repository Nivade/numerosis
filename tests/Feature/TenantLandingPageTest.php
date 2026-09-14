<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature;

use App\Models\Tenant\User as TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Actions\Tenancy\CreateTenantDomain;
use Nvade\Numerosis\Models\Central\Tenant as BaseTenant;
use Nvade\Numerosis\Models\Tenant\User as BaseTenantUser;
use Nvade\Numerosis\Tests\Support\TestTenant;
use Nvade\Numerosis\Tests\TestCase;

/**
 * Core's `/` on a tenant domain, registered in `routes/tenant.php` since the
 * Filament tenant panel — which owned that path — was deleted.
 *
 * The signed-in case is the one that broke: `layouts/⚡header.blade.php`
 * reads the **central** guard for the account menu but gated it with
 * `@auth`, which consults the **default** guard, and inside tenancy that is
 * the tenant one. So a tenant-guard session made the directive true and the
 * value null, and the first `$this->user->initials()` was a 500 on every
 * tenant page using this layout. `withoutExceptionHandling()` is what makes
 * that legible: the rendered 500 page says only "Server Error".
 */
class TenantLandingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_tenant_domain_serves_the_landing_page_to_an_unauthenticated_visitor(): void
    {
        $tenant = $this->tenantOnItsOwnDomain();

        $this->withoutExceptionHandling()
            ->get('http://'.$this->tenantDomain($tenant->id).'/')
            ->assertOk();
    }

    public function test_a_tenant_domain_serves_the_landing_page_to_a_tenant_guard_session(): void
    {
        $tenant = $this->tenantOnItsOwnDomain();

        /** @var BaseTenantUser $user */
        $user = $tenant->run(fn (): BaseTenantUser => TenantUser::factory()->create());

        $tenant->run(function () use ($tenant, $user): void {
            $this->actingAsTenantUser($user);

            $this->withoutExceptionHandling()
                ->get('http://'.$this->tenantDomain($tenant->id).'/')
                ->assertOk();
        });
    }

    private function tenantOnItsOwnDomain(): BaseTenant
    {
        $tenant = TestTenant::provisioned();

        CreateTenantDomain::run($tenant, $tenant->id);

        return $tenant;
    }
}
