<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Observers;

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
use App\Models\Tenant\User as TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Nvade\Numerosis\Actions\Tenancy\MarkTenantProvisioned;
use Nvade\Numerosis\Events\Tenancy\MemberJoined;
use Nvade\Numerosis\Events\Tenancy\MemberRemoved;
use Nvade\Numerosis\Tests\TestCase;

class MembershipObserverTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Attached from the `CentralUser` side on purpose. `TenantPivot::boot()`
     * only calls `triggerSyncEvent()` when the pivot's parent is `Syncable`,
     * which `CentralUser` is and `Tenant` is not — so `$tenant->users()->attach()`
     * would never reach stancl's `UpdateSyncedResource` and the `Queue::fake()`
     * below would be decorative. This way the fake is load-bearing: with it,
     * the observer is the only thing that can have created the row.
     */
    public function test_attaching_a_member_to_a_provisioned_tenant_creates_the_tenant_side_row(): void
    {
        $tenant = Tenant::factory()->create();
        MarkTenantProvisioned::run($tenant);
        $user = CentralUser::factory()->create();

        Queue::fake();

        $user->tenants()->attach($tenant, ['role' => 'member']);

        $tenant->run(function () use ($user): void {
            $this->assertNotNull(TenantUser::where('global_id', $user->global_id)->first());
        });
    }

    /**
     * Real queue, not faked: `BackfillTenantUsers` is `ShouldQueue`, and the
     * test needs it to actually run once the tenant is marked provisioned.
     *
     * Attached from the `Tenant` side here, the opposite of the test above and
     * for the same reason: that side is not `Syncable`, so stancl's own sync
     * stays out of it and the assertion below is about the observer's
     * provisioned-gate alone.
     */
    public function test_attaching_a_member_to_an_unprovisioned_tenant_creates_no_row_until_backfilled(): void
    {
        $tenant = Tenant::factory()->create();
        $user = CentralUser::factory()->create();

        // 'owner', because MarkTenantProvisioned only dispatches
        // TenantProvisioned (what BackfillTenantUsers listens for) when the
        // tenant has one.
        $tenant->users()->attach($user->global_id, ['role' => 'owner']);

        $tenant->run(function () use ($user): void {
            $this->assertNull(TenantUser::where('global_id', $user->global_id)->first());
        });

        MarkTenantProvisioned::run($tenant);

        $tenant->run(function () use ($user): void {
            $this->assertNotNull(TenantUser::where('global_id', $user->global_id)->first());
        });
    }

    public function test_attaching_and_detaching_a_member_dispatches_the_membership_events(): void
    {
        Event::fake([MemberJoined::class, MemberRemoved::class]);

        $tenant = Tenant::factory()->create();
        $user = CentralUser::factory()->create();
        $inviter = CentralUser::factory()->create();

        $tenant->users()->attach($user->global_id, ['role' => 'member', 'invited_by' => $inviter->global_id]);

        Event::assertDispatched(fn (MemberJoined $e): bool => $e->tenantId === $tenant->id
            && $e->globalUserId === $user->global_id
            && $e->role === 'member'
            && $e->invitedBy === $inviter->global_id);

        $tenant->users()->detach($user->global_id);

        Event::assertDispatched(fn (MemberRemoved $e): bool => $e->tenantId === $tenant->id
            && $e->globalUserId === $user->global_id
            && $e->role === 'member');
    }
}
