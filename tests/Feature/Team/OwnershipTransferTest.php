<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Team;

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
use App\Models\Tenant\User as TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\PendingCommand;
use Illuminate\Testing\TestResponse;
use Nvade\Numerosis\Actions\Auth\DeleteUserAccount;
use Nvade\Numerosis\Actions\Billing\SyncTenantToStripe;
use Nvade\Numerosis\Actions\Tenancy\NominateTenantOwner;
use Nvade\Numerosis\Actions\Tenancy\TransferTenantOwnership;
use Nvade\Numerosis\Enums\Billing\SubscriptionStatus;
use Nvade\Numerosis\Enums\Tenancy\MembershipRole;
use Nvade\Numerosis\Events\Tenancy\TenantOwnershipTransferred;
use Nvade\Numerosis\Models\Central\CentralUser as BaseCentralUser;
use Nvade\Numerosis\Models\Central\Membership;
use Nvade\Numerosis\Models\Central\OwnershipNomination;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Notifications\Tenancy\OwnershipNominationNotification;
use Nvade\Numerosis\Routing\RouteNames;
use Nvade\Numerosis\Tests\TestCase;
use Symfony\Component\HttpFoundation\Response;

class OwnershipTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_owner_nominates_and_the_nominee_accepts(): void
    {
        Event::fake([TenantOwnershipTransferred::class]);
        Notification::fake();

        [$tenant, $domain] = $this->tenant();
        [$ownerCentral, $owner] = $this->member($tenant, MembershipRole::Owner);
        [$targetCentral] = $this->member($tenant, MembershipRole::Member);

        $this->actingAsTenantUser($owner);

        $this->from('http://'.$domain.'/team')
            ->post('http://'.$domain.'/team/ownership', [
                'membership' => $this->membership($tenant, $targetCentral)->getKey(),
                'password' => 'password',
            ])
            ->assertRedirect('http://'.$domain.'/team');

        Notification::assertSentTo($targetCentral, OwnershipNominationNotification::class);

        $nomination = OwnershipNomination::query()->where('tenant_id', $tenant->getKey())->firstOrFail();

        $this->acceptAs($targetCentral, $nomination)->assertRedirect();

        $this->assertSame(MembershipRole::Owner, $this->membership($tenant, $targetCentral)->role);
        $this->assertSame(MembershipRole::Admin, $this->membership($tenant, $ownerCentral)->role);
        $this->assertSame(1, Membership::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('role', MembershipRole::Owner->value)
            ->count());

        Event::assertDispatched(fn (TenantOwnershipTransferred $event): bool => $event->tenantId === $tenant->getKey()
            && $event->fromGlobalUserId === $ownerCentral->global_id
            && $event->toGlobalUserId === $targetCentral->global_id);
    }

    public function test_the_subscription_stays_on_the_tenant_and_the_customer_is_resynced(): void
    {
        [$tenant] = $this->tenant();
        $this->member($tenant, MembershipRole::Owner);
        [$targetCentral] = $this->member($tenant, MembershipRole::Member);

        $subscription = $this->subscription($tenant, SubscriptionStatus::Active);

        Queue::fake();

        TransferTenantOwnership::run($tenant, $this->membership($tenant, $targetCentral));

        $subscription->refresh();

        $this->assertSame($tenant->id, $subscription->subscribable_id);
        $this->assertSame('sub_ownership', $subscription->stripe_id);

        SyncTenantToStripe::assertPushed(fn ($action, $params): bool => $params[0]->id === $tenant->id);
    }

    public function test_a_delinquent_subscription_refuses_the_transfer(): void
    {
        [$tenant, $domain] = $this->tenant();
        [, $owner] = $this->member($tenant, MembershipRole::Owner);
        [$targetCentral] = $this->member($tenant, MembershipRole::Member);

        $this->subscription($tenant, SubscriptionStatus::PastDue);

        $this->actingAsTenantUser($owner);

        $this->from('http://'.$domain.'/team')
            ->post('http://'.$domain.'/team/ownership', [
                'membership' => $this->membership($tenant, $targetCentral)->getKey(),
                'password' => 'password',
            ])
            ->assertSessionHasErrors('membership', errorBag: 'ownershipTransfer');

        $this->assertSame(MembershipRole::Member, $this->membership($tenant, $targetCentral)->role);
    }

    public function test_an_expired_nomination_cannot_be_accepted(): void
    {
        [$tenant] = $this->tenant();
        $this->member($tenant, MembershipRole::Owner);
        [$targetCentral] = $this->member($tenant, MembershipRole::Member);

        $nomination = NominateTenantOwner::run($tenant, $this->membership($tenant, $targetCentral));

        $this->travel(73)->hours();

        // Signed with an explicit future expiry, or `ValidateSignature`
        // answers first and the row's own expiry is never reached.
        $this->acceptAs($targetCentral, $nomination, now()->addHour())
            ->assertRedirect(route(RouteNames::tenantsMine()));

        $this->assertSame(MembershipRole::Member, $this->membership($tenant, $targetCentral)->role);
    }

    public function test_a_nomination_offered_to_someone_else_is_refused(): void
    {
        [$tenant] = $this->tenant();
        $this->member($tenant, MembershipRole::Owner);
        [$targetCentral] = $this->member($tenant, MembershipRole::Member);
        [$bystander] = $this->member($tenant, MembershipRole::Member);

        $nomination = NominateTenantOwner::run($tenant, $this->membership($tenant, $targetCentral));

        $this->acceptAs($bystander, $nomination)->assertRedirect(route(RouteNames::tenantsMine()));

        $this->assertSame(MembershipRole::Member, $this->membership($tenant, $bystander)->role);
    }

    public function test_a_non_owner_cannot_nominate(): void
    {
        [$tenant, $domain] = $this->tenant();
        $this->member($tenant, MembershipRole::Owner);
        [, $admin] = $this->member($tenant, MembershipRole::Admin);
        [$targetCentral] = $this->member($tenant, MembershipRole::Member);

        $this->actingAsTenantUser($admin);

        $this->post('http://'.$domain.'/team/ownership', [
            'membership' => $this->membership($tenant, $targetCentral)->getKey(),
            'password' => 'password',
        ])->assertForbidden();

        $this->assertDatabaseCount('tenant_ownership_nominations', 0, 'central');
    }

    public function test_a_member_of_another_tenant_cannot_be_nominated(): void
    {
        [$tenant, $domain] = $this->tenant();
        [, $owner] = $this->member($tenant, MembershipRole::Owner);

        [$otherTenant] = $this->tenant();
        [$outsider] = $this->member($otherTenant, MembershipRole::Member);

        $this->actingAsTenantUser($owner);

        $this->post('http://'.$domain.'/team/ownership', [
            'membership' => $this->membership($otherTenant, $outsider)->getKey(),
            'password' => 'password',
        ])->assertForbidden();
    }

    public function test_a_member_who_has_not_joined_cannot_be_nominated(): void
    {
        [$tenant, $domain] = $this->tenant();
        [, $owner] = $this->member($tenant, MembershipRole::Owner);
        [$pending] = $this->member($tenant, MembershipRole::Member);

        $this->membership($tenant, $pending)->update(['joined_at' => null]);

        $this->actingAsTenantUser($owner);

        $this->post('http://'.$domain.'/team/ownership', [
            'membership' => $this->membership($tenant, $pending)->getKey(),
            'password' => 'password',
        ])->assertForbidden();
    }

    public function test_the_owner_can_revoke_a_nomination(): void
    {
        [$tenant, $domain] = $this->tenant();
        [, $owner] = $this->member($tenant, MembershipRole::Owner);
        [$targetCentral] = $this->member($tenant, MembershipRole::Member);

        $nomination = NominateTenantOwner::run($tenant, $this->membership($tenant, $targetCentral));

        $this->actingAsTenantUser($owner);

        $this->from('http://'.$domain.'/team')
            ->delete('http://'.$domain.'/team/ownership/'.$nomination->ulid)
            ->assertRedirect('http://'.$domain.'/team');

        $this->assertDatabaseCount('tenant_ownership_nominations', 0, 'central');
    }

    public function test_the_former_owner_can_delete_their_account(): void
    {
        [$tenant] = $this->tenant();
        [$ownerCentral] = $this->member($tenant, MembershipRole::Owner);
        [$targetCentral] = $this->member($tenant, MembershipRole::Member);

        $this->assertFalse(DeleteUserAccount::run($ownerCentral));

        TransferTenantOwnership::run($tenant, $this->membership($tenant, $targetCentral));

        $ownerCentral->refresh();

        $this->assertTrue(DeleteUserAccount::run($ownerCentral));
    }

    public function test_the_staff_override_skips_acceptance_and_is_logged(): void
    {
        [$tenant] = $this->tenant();
        [$ownerCentral] = $this->member($tenant, MembershipRole::Owner);
        [$targetCentral] = $this->member($tenant, MembershipRole::Member);

        $command = $this->artisan('tenancy:transfer-ownership', [
            'tenant' => $tenant->id,
            'email' => $targetCentral->email,
        ]);

        // `artisan()` is typed `PendingCommand|int`, and the int branch has no
        // expectation methods on it. `run()` executes it here rather than on
        // destruct, which is after the assertions below.
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsConfirmation("Make {$targetCentral->email} the owner of {$tenant->id}, replacing {$ownerCentral->email}?", 'yes')
            ->assertSuccessful()
            ->run();

        $this->assertSame(MembershipRole::Owner, $this->membership($tenant, $targetCentral)->role);
        $this->assertSame(MembershipRole::Admin, $this->membership($tenant, $ownerCentral)->role);

        $this->assertDatabaseHas('activity_log', [
            'description' => "Ownership of {$tenant->id} reassigned from {$ownerCentral->email} to {$targetCentral->email} by staff",
        ], 'central');
    }

    public function test_transferring_to_the_sitting_owner_is_a_no_op(): void
    {
        Event::fake([TenantOwnershipTransferred::class]);

        [$tenant] = $this->tenant();
        [$ownerCentral] = $this->member($tenant, MembershipRole::Owner);

        TransferTenantOwnership::run($tenant, $this->membership($tenant, $ownerCentral));

        $this->assertSame(MembershipRole::Owner, $this->membership($tenant, $ownerCentral)->role);

        Event::assertNotDispatched(TenantOwnershipTransferred::class);
    }

    /**
     * @return array{0: Tenant, 1: string}
     */
    private function tenant(): array
    {
        $id = 'own'.substr(uniqid(), -8);

        return [$this->createTenantWithDomain($id, 'Owned Tenant'), $this->tenantDomain($id)];
    }

    /**
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

        $user = $tenant->run(fn (): TenantUser => TenantUser::where('global_id', $central->global_id)->firstOrFail());

        $this->assertInstanceOf(TenantUser::class, $user);

        return [$central, $user];
    }

    private function membership(Tenant $tenant, BaseCentralUser $user): Membership
    {
        return Membership::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('global_user_id', $user->global_id)
            ->firstOrFail();
    }

    private function subscription(Tenant $tenant, SubscriptionStatus $status): Subscription
    {
        return Subscription::query()->create([
            'type' => 'default',
            'stripe_id' => 'sub_ownership',
            'stripe_status' => $status->value,
            'stripe_price' => 'price_ownership',
            'quantity' => 1,
            'subscribable_id' => $tenant->getKey(),
            'subscribable_type' => Tenant::class,
        ]);
    }

    /**
     * @return TestResponse<Response>
     */
    private function acceptAs(BaseCentralUser $user, OwnershipNomination $nomination, ?Carbon $expiry = null): TestResponse
    {
        $url = URL::temporarySignedRoute(
            RouteNames::ownershipNominationShow(),
            $expiry ?? $nomination->expires_at,
            ['nomination' => $nomination->getRouteKey()],
        );

        tenancy()->end();

        return $this->actingAsCentralUser($user)->post($url);
    }
}
