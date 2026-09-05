<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Tenancy;

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Nvade\Numerosis\Actions\Tenancy\AddTenantOwner;
use Nvade\Numerosis\Actions\Tenancy\MarkTenantProvisioned;
use Nvade\Numerosis\Actions\Tenancy\PromoteFirstUserToAdmin;
use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;
use Nvade\Numerosis\Data\Tenancy\TenantRegistrationData;
use Nvade\Numerosis\Events\Auth\AdminGranted;
use Nvade\Numerosis\Tests\TestCase;

class PromoteFirstUserToAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_dispatches_admin_granted_for_the_promoted_user(): void
    {
        $tenant = Tenant::factory()->create();
        MarkTenantProvisioned::run($tenant);
        $owner = CentralUser::factory()->create();

        AddTenantOwner::run($tenant, new TenantProvisionData(
            registration: TenantRegistrationData::from([
                'company_name' => 'Admin Grant Co',
                'domain' => (string) $tenant->getTenantKey(),
                'global_id' => $owner->global_id,
            ]),
        ));

        Event::fake([AdminGranted::class]);

        PromoteFirstUserToAdmin::run($tenant);

        Event::assertDispatched(fn (AdminGranted $e): bool => $e->globalId === $owner->global_id
            && $e->grantedBy === null
            && $e->tenantId === $tenant->id);
    }
}
