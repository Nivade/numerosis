<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Staff;

use App\Models\Central\CentralUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Nvade\Numerosis\Actions\Tenancy\ReopenTenant;
use Nvade\Numerosis\Actions\Tenancy\RestoreTenant;
use Nvade\Numerosis\Actions\Tenancy\SuspendTenant;
use Nvade\Numerosis\Actions\Tenancy\TransferTenantOwnership;
use Nvade\Numerosis\Database\Seeders\RoleAndPermissionSeeder;
use Nvade\Numerosis\Enums\Auth\PermissionAction;
use Nvade\Numerosis\Enums\Auth\PermissionContext;
use Nvade\Numerosis\Enums\Tenancy\MembershipRole;
use Nvade\Numerosis\Features\Admin\StaffPanelFeature;
use Nvade\Numerosis\Features\FeatureRegistry;
use Nvade\Numerosis\Models\Central\CentralUser as BaseCentralUser;
use Nvade\Numerosis\Models\Central\Membership;
use Nvade\Numerosis\Tests\Support\TestTenant;
use Nvade\Numerosis\Tests\TestCase;

class StaffTenantScreenTest extends TestCase
{
    use RefreshDatabase;

    private bool $permissionsSeeded = false;

    protected function setUp(): void
    {
        FeatureRegistry::forceForTesting([StaffPanelFeature::class]);

        parent::setUp();
    }

    /**
     * The count runs inside the tenant, which is the one place on these
     * screens where a leak would carry into the next request.
     */
    public function test_the_tenant_side_count_leaves_tenancy_ended(): void
    {
        $tenant = TestTenant::provisioned(['provisioned_at' => now()]);

        $component = Livewire::actingAs($this->admin())
            ->test('numerosis-pages::staff.tenant', ['tenantId' => $tenant->getKey()]);

        $component->assertOk();

        // The dash is what the tenant-side stat renders when the count came
        // back null, so this is what keeps the tenancy check below honest.
        $component->assertSee(__('numerosis::staff.tenant.tenant_users'))->assertDontSee('—');

        $this->assertNull(tenancy()->tenant);
        $this->assertFalse(tenancy()->initialized);
    }

    public function test_suspending_goes_through_the_action_and_confirms_it(): void
    {
        SuspendTenant::shouldRun();

        $tenant = TestTenant::provisioned(['provisioned_at' => now()]);
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test('numerosis-pages::staff.tenant', ['tenantId' => $tenant->getKey()])
            ->call('suspend')
            ->assertDispatched('notify', message: "Tenant {$tenant->id} suspended by staff");
    }

    public function test_restoring_goes_through_the_action_and_confirms_it(): void
    {
        RestoreTenant::shouldRun();

        $tenant = TestTenant::provisioned(['provisioned_at' => now(), 'suspended_at' => now()]);
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test('numerosis-pages::staff.tenant', ['tenantId' => $tenant->getKey()])
            ->call('restore')
            ->assertDispatched('notify', message: "Tenant {$tenant->id} restored by staff");
    }

    public function test_reopening_goes_through_the_action_and_confirms_it(): void
    {
        ReopenTenant::shouldRun();

        $tenant = TestTenant::provisioned(['provisioned_at' => now(), 'closed_at' => now()]);
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test('numerosis-pages::staff.tenant', ['tenantId' => $tenant->getKey()])
            ->call('reopen')
            ->assertDispatched('notify', message: "Tenant {$tenant->id} reopened by staff");
    }

    /**
     * Reopening is gated on its own verb, not on `restore`: undoing a closure
     * and lifting a suspension are different decisions.
     */
    public function test_reopening_is_refused_without_the_reopen_permission(): void
    {
        ReopenTenant::shouldNotRun();

        $tenant = TestTenant::provisioned(['provisioned_at' => now(), 'closed_at' => now()]);

        $staff = $this->userWithoutPermissions();
        $staff->givePermissionTo(PermissionAction::Restore->value.' '.PermissionContext::Tenants->value);

        Livewire::actingAs($this->withConfirmedTwoFactor($staff))
            ->test('numerosis-pages::staff.tenant', ['tenantId' => $tenant->getKey()])
            ->call('reopen')
            ->assertForbidden();
    }

    public function test_reassigning_the_owner_goes_through_the_action_and_confirms_it(): void
    {
        TransferTenantOwnership::shouldRun();

        $tenant = TestTenant::provisioned(['provisioned_at' => now()]);
        $admin = $this->admin();

        $member = CentralUser::factory()->create();
        $tenant->users()->attach($member->global_id, ['role' => MembershipRole::Member->value, 'joined_at' => now()]);

        $membership = Membership::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('global_user_id', $member->global_id)
            ->firstOrFail();

        Livewire::actingAs($admin)
            ->test('numerosis-pages::staff.tenant', ['tenantId' => $tenant->getKey()])
            ->call('reassignOwner', $membership->getKey())
            ->assertDispatched('notify', message: "Ownership of {$tenant->id} reassigned to {$member->email} by staff");
    }

    /**
     * The membership id is a plain Livewire argument, so a staff user cannot
     * reach one belonging to another tenant through it.
     */
    public function test_a_membership_of_another_tenant_is_ignored(): void
    {
        TransferTenantOwnership::shouldNotRun();

        $tenant = TestTenant::provisioned(['provisioned_at' => now()]);
        $other = TestTenant::provisioned(['provisioned_at' => now()]);

        $member = CentralUser::factory()->create();
        $other->users()->attach($member->global_id, ['role' => MembershipRole::Member->value, 'joined_at' => now()]);

        $membership = Membership::query()
            ->where('tenant_id', $other->getKey())
            ->where('global_user_id', $member->global_id)
            ->firstOrFail();

        Livewire::actingAs($this->admin())
            ->test('numerosis-pages::staff.tenant', ['tenantId' => $tenant->getKey()])
            ->call('reassignOwner', $membership->getKey());
    }

    public function test_a_user_without_permissions_cannot_suspend(): void
    {
        SuspendTenant::shouldNotRun();

        $tenant = TestTenant::provisioned(['provisioned_at' => now()]);

        Livewire::actingAs($this->userWithoutPermissions())
            ->test('numerosis-pages::staff.tenant', ['tenantId' => $tenant->getKey()])
            ->call('suspend')
            ->assertForbidden();
    }

    /**
     * The index lists every tenant, which is the whole point of these screens
     * and would be far too permissive on a tenant-side one.
     */
    public function test_the_index_lists_tenants_a_staff_user_does_not_belong_to(): void
    {
        $tenant = TestTenant::provisioned(['name' => 'Someone Elses Co', 'provisioned_at' => now()]);

        Livewire::actingAs($this->admin())
            ->test('numerosis-pages::staff.tenants')
            ->assertSee('Someone Elses Co')
            ->assertSee($tenant->getKey());
    }

    public function test_the_index_filters_by_suspended(): void
    {
        $suspended = TestTenant::provisioned(['provisioned_at' => now(), 'suspended_at' => now()]);
        $active = TestTenant::provisioned(['provisioned_at' => now()]);

        Livewire::actingAs($this->admin())
            ->test('numerosis-pages::staff.tenants')
            ->set('status', 'suspended')
            ->assertSee($suspended->getKey())
            ->assertDontSee($active->getKey());
    }

    private function admin(): BaseCentralUser
    {
        $admin = $this->userWithoutPermissions();
        $admin->assignRole('admin');

        return $this->withConfirmedTwoFactor($admin);
    }

    /** The decoy covers `CentralUserObserver`'s promotion of the first user. */
    private function userWithoutPermissions(): BaseCentralUser
    {
        $this->seedPermissionsOnce();

        CentralUser::factory()->create();

        return CentralUser::factory()->create();
    }

    /**
     * Once per test: every central role and permission row is written through
     * the `central` connection, which is autocommit, so re-seeding inside one
     * test leaves more rows for teardown to chase than it needs to.
     */
    private function seedPermissionsOnce(): void
    {
        if ($this->permissionsSeeded) {
            return;
        }

        $this->permissionsSeeded = true;

        (new RoleAndPermissionSeeder)->run();
    }
}
