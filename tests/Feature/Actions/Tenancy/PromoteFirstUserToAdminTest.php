<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Tenancy;

use App\Models\Central\CentralUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Nvade\Numerosis\Actions\Tenancy\AddTenantOwner;
use Nvade\Numerosis\Actions\Tenancy\MarkTenantProvisioned;
use Nvade\Numerosis\Actions\Tenancy\PromoteFirstUserToAdmin;
use Nvade\Numerosis\Events\Auth\AdminGranted;
use Nvade\Numerosis\Exceptions\Tenancy\NoPromotableUser;
use Nvade\Numerosis\Tests\Concerns\BuildsTenantProvisionData;
use Nvade\Numerosis\Tests\Support\TestTenant;
use Nvade\Numerosis\Tests\TestCase;

class PromoteFirstUserToAdminTest extends TestCase
{
    use BuildsTenantProvisionData;
    use RefreshDatabase;

    public function test_it_dispatches_admin_granted_for_the_promoted_user(): void
    {
        $tenant = TestTenant::withDatabaseOnly();
        MarkTenantProvisioned::run($tenant);
        $owner = CentralUser::factory()->create();

        AddTenantOwner::run($this->ownerProvisionRow($tenant, $owner));

        Event::fake([AdminGranted::class]);

        PromoteFirstUserToAdmin::run($this->ownerProvisionRow($tenant, $owner));

        Event::assertDispatched(fn (AdminGranted $e): bool => $e->globalId === $owner->global_id
            && $e->grantedBy === null
            && $e->tenantId === $tenant->id);
    }

    public function test_a_throw_inside_the_promotion_does_not_leave_tenancy_initialized(): void
    {
        $tenant = TestTenant::withDatabaseOnly();
        MarkTenantProvisioned::run($tenant);
        $owner = CentralUser::factory()->create();

        // Deliberately no AddTenantOwner::run() here: that step is what
        // writes the tenant-side user row, so skipping it leaves the tenant
        // database with no non-bot user for PromoteFirstUserToAdmin to find
        // — the reachable NoPromotableUser path, not a synthetic failure.
        $this->expectException(NoPromotableUser::class);

        try {
            PromoteFirstUserToAdmin::run($this->ownerProvisionRow($tenant, $owner));
        } finally {
            $this->assertFalse(tenancy()->initialized);
        }
    }
}
