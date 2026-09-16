<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Team;

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
use App\Models\Tenant\User as TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Nvade\Numerosis\Enums\Tenancy\MembershipRole;
use Nvade\Numerosis\Events\Tenancy\MemberRemoved;
use Nvade\Numerosis\Events\Tenancy\MemberRoleChanged;
use Nvade\Numerosis\Models\Central\CentralUser as BaseCentralUser;
use Nvade\Numerosis\Models\Central\Membership;
use Nvade\Numerosis\Routing\RouteNames;
use Nvade\Numerosis\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class TeamMembersTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_member_sees_the_team(): void
    {
        [$tenant, $domain] = $this->tenant();
        [, $actor] = $this->member($tenant, MembershipRole::Member);
        [$otherCentral] = $this->member($tenant, MembershipRole::Admin);

        $email = $otherCentral->email;

        $this->assertIsString($email);
        $this->actingAsTenantUser($actor);

        $this->get('http://'.$domain.'/team')
            ->assertOk()
            ->assertSee($email);
    }

    public function test_an_admin_can_change_a_members_role(): void
    {
        Event::fake([MemberRoleChanged::class]);

        [$tenant, $domain] = $this->tenant();
        [, $actor] = $this->member($tenant, MembershipRole::Admin);
        [$target] = $this->member($tenant, MembershipRole::Member);

        $this->actingAsTenantUser($actor);

        $this->from('http://'.$domain.'/team')
            ->patch($this->memberUrl($domain, $this->membership($tenant, $target)), ['role' => 'viewer'])
            ->assertRedirect('http://'.$domain.'/team');

        $this->assertSame(MembershipRole::Viewer, $this->membership($tenant, $target)->role);

        Event::assertDispatched(fn (MemberRoleChanged $event): bool => $event->globalUserId === $target->global_id
            && $event->from === MembershipRole::Member
            && $event->to === MembershipRole::Viewer);
    }

    public function test_an_admin_can_remove_a_member_and_member_removed_fires_once(): void
    {
        Event::fake([MemberRemoved::class]);

        [$tenant, $domain] = $this->tenant();
        [, $actor] = $this->member($tenant, MembershipRole::Admin);
        [$target] = $this->member($tenant, MembershipRole::Member);
        $membership = $this->membership($tenant, $target);

        $this->actingAsTenantUser($actor);

        $this->from('http://'.$domain.'/team')
            ->delete($this->memberUrl($domain, $membership))
            ->assertRedirect('http://'.$domain.'/team');

        $this->assertDatabaseMissing('memberships', ['id' => $membership->id], 'central');

        Event::assertDispatched(MemberRemoved::class, 1);
    }

    /**
     * `memberships` is central, so binding `{membership}` on a tenant route
     * resolves any id regardless of owner, and every tenant's `admin` holds
     * `deleteAny invitations`. Only the policy's tenant check stands between
     * one tenant's admin and another tenant's rows.
     */
    public function test_a_foreign_tenant_membership_is_not_reachable(): void
    {
        [$tenant, $domain] = $this->tenant();
        [, $actor] = $this->member($tenant, MembershipRole::Admin);

        [$otherTenant] = $this->tenant();
        [$victim] = $this->member($otherTenant, MembershipRole::Member);
        $foreign = $this->membership($otherTenant, $victim);

        $this->actingAsTenantUser($actor);

        $this->patch($this->memberUrl($domain, $foreign), ['role' => 'viewer'])->assertForbidden();
        $this->delete($this->memberUrl($domain, $foreign))->assertForbidden();

        $this->assertDatabaseHas('memberships', ['id' => $foreign->id, 'role' => 'member'], 'central');
    }

    public function test_the_owner_can_be_neither_removed_nor_demoted(): void
    {
        [$tenant, $domain] = $this->tenant();
        [, $actor] = $this->member($tenant, MembershipRole::Admin);
        [$owner] = $this->member($tenant, MembershipRole::Owner);
        $ownerMembership = $this->membership($tenant, $owner);

        $this->actingAsTenantUser($actor);

        $this->patch($this->memberUrl($domain, $ownerMembership), ['role' => 'member'])->assertForbidden();
        $this->delete($this->memberUrl($domain, $ownerMembership))->assertForbidden();

        $this->assertDatabaseHas('memberships', ['id' => $ownerMembership->id, 'role' => 'owner'], 'central');
    }

    public function test_owner_is_not_an_assignable_role(): void
    {
        [$tenant, $domain] = $this->tenant();
        [, $actor] = $this->member($tenant, MembershipRole::Admin);
        [$target] = $this->member($tenant, MembershipRole::Member);

        $this->actingAsTenantUser($actor);

        $this->from('http://'.$domain.'/team')
            ->patch($this->memberUrl($domain, $this->membership($tenant, $target)), ['role' => 'owner'])
            ->assertSessionHasErrors('role', errorBag: 'memberRole');

        $this->assertSame(MembershipRole::Member, $this->membership($tenant, $target)->role);
    }

    public function test_removing_the_last_admin_is_refused_but_a_second_admin_is_not(): void
    {
        [$tenant, $domain] = $this->tenant();
        [, $actor] = $this->member($tenant, MembershipRole::Owner);
        [$admin] = $this->member($tenant, MembershipRole::Admin);
        $lastAdmin = $this->membership($tenant, $admin);

        $this->actingAsTenantUser($actor);

        $this->delete($this->memberUrl($domain, $lastAdmin))->assertForbidden();

        [$secondAdmin] = $this->member($tenant, MembershipRole::Admin);

        $this->from('http://'.$domain.'/team')
            ->delete($this->memberUrl($domain, $this->membership($tenant, $secondAdmin)))
            ->assertRedirect('http://'.$domain.'/team');

        $this->assertDatabaseHas('memberships', ['id' => $lastAdmin->id], 'central');
    }

    public function test_demoting_the_last_admin_is_refused(): void
    {
        [$tenant, $domain] = $this->tenant();
        [, $actor] = $this->member($tenant, MembershipRole::Owner);
        [$admin] = $this->member($tenant, MembershipRole::Admin);
        $lastAdmin = $this->membership($tenant, $admin);

        $this->actingAsTenantUser($actor);

        $this->from('http://'.$domain.'/team')
            ->patch($this->memberUrl($domain, $lastAdmin), ['role' => 'member'])
            ->assertSessionHasErrors('role', errorBag: 'memberRole');

        $this->assertSame(MembershipRole::Admin, $this->membership($tenant, $admin)->role);
    }

    #[DataProvider('selfRemovableRoles')]
    public function test_a_member_can_leave_the_team(string $role): void
    {
        [$tenant, $domain] = $this->tenant();
        $this->member($tenant, MembershipRole::Owner);
        [$central, $actor] = $this->member($tenant, MembershipRole::from($role));
        $membership = $this->membership($tenant, $central);

        $this->actingAsTenantUser($actor);

        $this->delete($this->memberUrl($domain, $membership))
            ->assertRedirect(route(RouteNames::tenantsMine()));

        $this->assertDatabaseMissing('memberships', ['id' => $membership->id], 'central');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function selfRemovableRoles(): array
    {
        return [
            'admin' => ['admin'],
            'member' => ['member'],
            'viewer' => ['viewer'],
        ];
    }

    public function test_the_owner_cannot_leave_the_team(): void
    {
        [$tenant, $domain] = $this->tenant();
        [$central, $actor] = $this->member($tenant, MembershipRole::Owner);
        $membership = $this->membership($tenant, $central);

        $this->actingAsTenantUser($actor);

        $this->delete($this->memberUrl($domain, $membership))->assertForbidden();

        $this->assertDatabaseHas('memberships', ['id' => $membership->id], 'central');
    }

    public function test_a_viewer_cannot_reach_the_mutation_routes(): void
    {
        [$tenant, $domain] = $this->tenant();
        [, $actor] = $this->member($tenant, MembershipRole::Viewer);
        [$target] = $this->member($tenant, MembershipRole::Member);
        $membership = $this->membership($tenant, $target);

        $this->actingAsTenantUser($actor);

        $this->patch($this->memberUrl($domain, $membership), ['role' => 'viewer'])->assertForbidden();
        $this->delete($this->memberUrl($domain, $membership))->assertForbidden();
    }

    /**
     * A removed member's tenant guard session outlives the removal, which is
     * what `EnsureTenantMembership` closes.
     */
    public function test_a_removed_member_loses_access_on_the_next_request(): void
    {
        [$tenant, $domain] = $this->tenant();
        [$central, $actor] = $this->member($tenant, MembershipRole::Member);

        $this->actingAsTenantUser($actor);

        $this->get('http://'.$domain.'/team')->assertOk();

        $this->membership($tenant, $central)->delete();

        $this->get('http://'.$domain.'/team')->assertRedirect(route(RouteNames::tenantsMine()));
    }

    /**
     * @return array{0: Tenant, 1: string}
     */
    private function tenant(): array
    {
        $id = 'team'.substr(uniqid(), -8);

        return [$this->createTenantWithDomain($id, 'Team Tenant'), $this->tenantDomain($id)];
    }

    /**
     * A central user attached to the tenant, with the tenant-side twin the
     * guard authenticates.
     *
     * @return array{0: BaseCentralUser, 1: TenantUser}
     */
    private function member(Tenant $tenant, MembershipRole $role): array
    {
        // Tenancy left initialized by an earlier request makes the tenant-side
        // twin `ResourceSyncing` attach a second membership of its own, which
        // the unique index then rejects.
        tenancy()->end();

        $central = CentralUser::factory()->create();

        $tenant->users()->attach($central->global_id, ['role' => $role->value, 'joined_at' => now()]);

        // The twin already exists: `MembershipObserver::created()` runs
        // `SyncTenantUserForMembership` on the attach above.
        $user = $tenant->run(fn (): TenantUser => TenantUser::where('global_id', $central->global_id)->firstOrFail());

        $this->assertInstanceOf(TenantUser::class, $user);

        return [$central, $user];
    }

    private function memberUrl(string $domain, Membership $membership): string
    {
        return 'http://'.$domain.'/team/members/'.$membership->id;
    }

    private function membership(Tenant $tenant, BaseCentralUser $user): Membership
    {
        return Membership::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('global_user_id', $user->global_id)
            ->firstOrFail();
    }
}
