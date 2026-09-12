<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Tenancy;

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
use App\Models\Tenant\User as TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Nvade\Numerosis\Actions\Tenancy\AddTenantOwner;
use Nvade\Numerosis\Actions\Tenancy\PromoteFirstUserToAdmin;
use Nvade\Numerosis\Tests\Concerns\BuildsTenantProvisionData;
use Nvade\Numerosis\Tests\Support\TestTenant;
use Nvade\Numerosis\Tests\TestCase;

class AddTenantOwnerTest extends TestCase
{
    use BuildsTenantProvisionData;
    use RefreshDatabase;

    /**
     * The queue is faked throughout, so neither stancl's `UpdateSyncedResource`
     * nor `Listeners\Tenancy\BackfillTenantUsers` can be what created the row.
     * Both are queued, and in production they land on the `default` queue while
     * provisioning runs on `provisioning` — no ordering guarantee between them.
     */
    public function test_it_creates_the_owners_tenant_side_row_before_the_tenant_is_provisioned(): void
    {
        $tenant = TestTenant::withDatabaseOnly();
        $user = CentralUser::factory()->create();

        // After the factory, not before: creating a tenant queues its own
        // database-creation jobs, which a fake would swallow.
        Queue::fake();

        AddTenantOwner::run($this->ownerProvisionRow($tenant, $user));

        $this->assertNull($tenant->refresh()->provisioned_at);

        $tenant->run(function () use ($user): void {
            $this->assertNotNull(TenantUser::where('global_id', $user->global_id)->first());
        });
    }

    /**
     * The ordering that broke: `FinalizeTenantProvisioning` runs
     * `PromoteFirstUserToAdmin` before `MarkTenantProvisioned`, so the owner's
     * row has to exist before anything dispatches `TenantProvisioned`.
     * Without it, promotion throws `NoPromotableUser` and the whole
     * provisioning chain burns its retries.
     */
    public function test_the_owner_is_promotable_immediately_after_being_added(): void
    {
        $tenant = TestTenant::withDatabaseOnly();
        $user = CentralUser::factory()->create();

        // After the factory, not before: creating a tenant queues its own
        // database-creation jobs, which a fake would swallow.
        Queue::fake();

        AddTenantOwner::run($this->ownerProvisionRow($tenant, $user));
        PromoteFirstUserToAdmin::run($this->ownerProvisionRow($tenant, $user));

        $tenant->run(function () use ($user): void {
            $this->assertTrue(TenantUser::where('global_id', $user->global_id)->firstOrFail()->hasRole('admin'));
        });
    }

    /**
     * A retried provision finds the pivot already written. The tenant-side row
     * still has to be created, so the guard cannot cover both.
     */
    public function test_a_rerun_creates_the_tenant_side_row_when_only_the_pivot_exists(): void
    {
        $tenant = TestTenant::withDatabaseOnly();
        $user = CentralUser::factory()->create();

        // After the factory, not before: creating a tenant queues its own
        // database-creation jobs, which a fake would swallow.
        Queue::fake();

        $user->tenants()->attach($tenant, ['role' => 'owner', 'joined_at' => now()]);

        $tenant->run(function () use ($user): void {
            $this->assertNull(TenantUser::where('global_id', $user->global_id)->first());
        });

        AddTenantOwner::run($this->ownerProvisionRow($tenant, $user));

        $tenant->run(function () use ($user): void {
            $this->assertNotNull(TenantUser::where('global_id', $user->global_id)->first());
        });
    }
}
