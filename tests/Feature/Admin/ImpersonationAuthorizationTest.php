<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Admin;

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Nvade\Numerosis\Actions\Admin\StartImpersonation;
use Nvade\Numerosis\Database\Seeders\RoleAndPermissionSeeder;
use Nvade\Numerosis\Enums\Tenancy\MembershipRole;
use Nvade\Numerosis\Features\Admin\ImpersonationFeature;
use Nvade\Numerosis\Features\Admin\StaffPanelFeature;
use Nvade\Numerosis\Features\FeatureRegistry;
use Nvade\Numerosis\Models\Central\CentralUser as BaseCentralUser;
use Nvade\Numerosis\Models\Central\ImpersonationSession;
use Nvade\Numerosis\Models\Central\Membership;
use Nvade\Numerosis\Models\Permission;
use Nvade\Numerosis\Models\Tenant\User as TenantUser;
use Nvade\Numerosis\Policies\Tenancy\TenantPolicy;
use Nvade\Numerosis\Tests\TestCase;

class ImpersonationAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private bool $permissionsSeeded = false;

    protected function setUp(): void
    {
        FeatureRegistry::forceForTesting([StaffPanelFeature::class, ImpersonationFeature::class]);

        parent::setUp();
    }

    public function test_the_ability_exists_on_the_central_guard_only(): void
    {
        $this->seedPermissionsOnce();

        $name = TenantPolicy::IMPERSONATE.' tenants';

        $this->assertTrue(
            Permission::on('central')->where('name', $name)->where('guard_name', 'web')->exists()
        );

        // A tenant admin holds every permission in every seeded context, so
        // the only thing keeping them off this one is its absence there.
        $this->assertFalse(
            Permission::on('central')->where('name', $name)->where('guard_name', 'tenant')->exists()
        );
    }

    public function test_a_staff_user_without_the_ability_cannot_mint_a_token(): void
    {
        [$tenant, $membership] = $this->tenantWithMembership();

        // Granted directly rather than through `admin`, which holds every
        // permission in the context including this one.
        $user = $this->staffWithout();

        Livewire::actingAs($user)
            ->test('numerosis-pages::staff.tenant', ['tenantId' => $tenant->getKey()])
            ->call('impersonate', $membership->getKey())
            ->assertForbidden();

        $this->assertSame(0, ImpersonationSession::query()->count());
    }

    public function test_a_staff_user_with_the_ability_mints_one_and_is_sent_to_the_tenant(): void
    {
        [$tenant, $membership] = $this->tenantWithMembership();

        Livewire::actingAs($this->staff())
            ->test('numerosis-pages::staff.tenant', ['tenantId' => $tenant->getKey()])
            ->call('impersonate', $membership->getKey())
            ->assertRedirectContains($tenant->baseUrl().'/impersonate/');

        $this->assertSame(1, ImpersonationSession::query()->count());
    }

    /**
     * The membership id is a plain Livewire argument, so another tenant's
     * member must not be reachable through it.
     */
    public function test_a_membership_of_another_tenant_mints_nothing(): void
    {
        [$tenant] = $this->tenantWithMembership();
        [, $otherMembership] = $this->tenantWithMembership('otherimp');

        Livewire::actingAs($this->staff())
            ->test('numerosis-pages::staff.tenant', ['tenantId' => $tenant->getKey()])
            ->call('impersonate', $otherMembership->getKey());

        $this->assertSame(0, ImpersonationSession::query()->count());
    }

    public function test_the_banner_renders_for_the_whole_session(): void
    {
        [$tenant, $membership] = $this->tenantWithMembership();

        $url = StartImpersonation::run($tenant, $membership->global_user_id, $this->staff());

        $this->get($url)->assertRedirect();

        $this->get($tenant->baseUrl())
            ->assertOk()
            ->assertSee('impersonate/exit')
            ->assertSee(__('numerosis::impersonation.exit'));
    }

    /**
     * @return array{0: Tenant, 1: Membership}
     */
    private function tenantWithMembership(string $prefix = 'imp'): array
    {
        $tenant = $this->createTenantWithDomain($prefix.substr(uniqid(), -8));

        tenancy()->end();

        $central = CentralUser::factory()->create();
        $tenant->users()->attach($central->global_id, ['role' => MembershipRole::Member->value, 'joined_at' => now()]);

        // The tenant-side twin is what the guard logs in, and it only exists
        // once the sync has run inside the tenant.
        $tenant->run(fn (): TenantUser => TenantUser::where('global_id', $central->global_id)->firstOrFail());

        tenancy()->end();

        $membership = Membership::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('global_user_id', $central->global_id)
            ->firstOrFail();

        return [$tenant, $membership];
    }

    /**
     * A staff user who may administer tenants and may not sign in as their
     * members, which is the split the separate ability exists for.
     */
    private function staffWithout(): BaseCentralUser
    {
        $this->seedPermissionsOnce();

        CentralUser::factory()->create();

        $user = CentralUser::factory()->create();
        $user->givePermissionTo(['viewAny tenants', 'view tenants', 'update tenants']);

        return $user;
    }

    /** The decoy covers `CentralUserObserver`'s promotion of the first user. */
    private function staff(): BaseCentralUser
    {
        $this->seedPermissionsOnce();

        CentralUser::factory()->create();

        $staff = CentralUser::factory()->create();
        $staff->assignRole('admin');

        return $staff;
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
