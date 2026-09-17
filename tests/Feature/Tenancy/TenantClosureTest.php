<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Tenancy;

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
use App\Models\Tenant\User as TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Nvade\Numerosis\Actions\Auth\DeleteUserAccount;
use Nvade\Numerosis\Actions\Tenancy\CloseTenant;
use Nvade\Numerosis\Actions\Tenancy\RestoreTenant;
use Nvade\Numerosis\Actions\Tenancy\SuspendTenant;
use Nvade\Numerosis\Enums\Billing\SubscriptionStatus;
use Nvade\Numerosis\Enums\Tenancy\MembershipRole;
use Nvade\Numerosis\Events\Tenancy\TenantClosed;
use Nvade\Numerosis\Events\Tenancy\TenantReopened;
use Nvade\Numerosis\Exceptions\Tenancy\TenantClosureBlocked;
use Nvade\Numerosis\Models\Central\CentralUser as BaseCentralUser;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Models\Central\SubscriptionItem;
use Nvade\Numerosis\Routing\RouteNames;
use Nvade\Numerosis\Testing\FakesStripe;
use Nvade\Numerosis\Tests\TestCase;

class TenantClosureTest extends TestCase
{
    use FakesStripe;
    use RefreshDatabase;

    public function test_the_owner_closes_the_workspace_and_billing_ends_at_period_end(): void
    {
        Event::fake([TenantClosed::class]);

        $this->fakeStripe();

        [$tenant, $domain] = $this->tenant();
        [, $owner] = $this->member($tenant, MembershipRole::Owner);
        $subscription = $this->subscription($tenant, SubscriptionStatus::Active);

        $this->actingAsTenantUser($owner);

        $this->from('http://'.$domain.'/team')
            ->post('http://'.$domain.'/team/close', [
                'name' => $tenant->name,
                'password' => 'password',
            ])
            ->assertRedirect('http://'.$domain.'/team');

        $tenant->refresh();
        $subscription->refresh();

        $this->assertTrue($tenant->isClosed());
        $this->assertNotNull($subscription->ends_at);
        $this->assertTrue($subscription->ends_at->isFuture());
        $this->assertTrue($subscription->onGracePeriod());
        $this->assertDatabaseHas('subscriptions', ['stripe_id' => $subscription->stripe_id], 'central');

        Event::assertDispatched(fn (TenantClosed $event): bool => $event->tenantId === $tenant->getKey());
    }

    public function test_a_wrong_workspace_name_does_not_close_it(): void
    {
        [$tenant, $domain] = $this->tenant();
        [, $owner] = $this->member($tenant, MembershipRole::Owner);

        $this->actingAsTenantUser($owner);

        $this->from('http://'.$domain.'/team')
            ->post('http://'.$domain.'/team/close', [
                'name' => 'not the workspace',
                'password' => 'password',
            ])
            ->assertSessionHasErrors('name', errorBag: 'closeTenant');

        $this->assertFalse($tenant->refresh()->isClosed());
    }

    public function test_a_non_owner_cannot_close_the_workspace(): void
    {
        [$tenant, $domain] = $this->tenant();
        $this->member($tenant, MembershipRole::Owner);
        [, $admin] = $this->member($tenant, MembershipRole::Admin);

        $this->actingAsTenantUser($admin);

        $this->post('http://'.$domain.'/team/close', [
            'name' => $tenant->name,
            'password' => 'password',
        ])->assertForbidden();

        $this->assertFalse($tenant->refresh()->isClosed());
    }

    public function test_an_unpaid_invoice_has_to_be_acknowledged(): void
    {
        $this->fakeStripe();

        [$tenant] = $this->tenant();
        $this->member($tenant, MembershipRole::Owner);
        $this->subscription($tenant, SubscriptionStatus::PastDue);

        try {
            CloseTenant::run($tenant);
            $this->fail('Closing a workspace with an unpaid invoice was not refused.');
        } catch (TenantClosureBlocked) {
            // The refusal is the subject; the acknowledged call below is what
            // proves it is not a blanket block.
        }

        $this->assertFalse($tenant->refresh()->isClosed());

        CloseTenant::run($tenant, true);

        $this->assertTrue($tenant->refresh()->isClosed());
    }

    public function test_a_closed_workspace_shows_the_notice_to_members_and_the_reopen_to_the_owner(): void
    {
        $this->fakeStripe();

        [$tenant, $domain] = $this->tenant();
        [, $owner] = $this->member($tenant, MembershipRole::Owner);
        [, $member] = $this->member($tenant, MembershipRole::Member);

        CloseTenant::run($tenant);

        $this->actingAsTenantUser($member);

        // The path, not the whole URL: `to_route()` builds it off the forced
        // root URL this suite sets, which is the central host.
        $this->get('http://'.$domain.'/team')
            ->assertRedirectContains('/account-closed');

        $this->get('http://'.$domain.'/account-closed')->assertOk()->assertSeeHtml('is closed')
            ->assertDontSee('Reopen this workspace');

        $this->actingAsTenantUser($owner);

        $this->get('http://'.$domain.'/account-closed')
            ->assertOk()
            ->assertSee('Reopen this workspace');
    }

    public function test_an_admin_sees_the_closure_detail_but_not_the_reopen_control(): void
    {
        $this->fakeStripe();

        [$tenant, $domain] = $this->tenant();
        [, $owner] = $this->member($tenant, MembershipRole::Owner);
        [, $admin] = $this->member($tenant, MembershipRole::Admin);

        CloseTenant::run($tenant);

        $closedOn = $tenant->refresh()->closed_at?->toFormattedDayDateString();
        $this->assertNotNull($closedOn);

        $this->actingAsTenantUser($admin);

        $this->get('http://'.$domain.'/account-closed')
            ->assertOk()
            ->assertSee($closedOn)
            ->assertDontSee('Reopen this workspace');

        $this->actingAsTenantUser($owner);

        $this->get('http://'.$domain.'/account-closed')
            ->assertOk()
            ->assertSee($closedOn)
            ->assertSee('Reopen this workspace');
    }

    public function test_reopening_within_the_grace_period_restores_the_same_subscription(): void
    {
        Event::fake([TenantReopened::class]);

        $this->fakeStripe();

        [$tenant, $domain] = $this->tenant();
        [, $owner] = $this->member($tenant, MembershipRole::Owner);
        $subscription = $this->subscription($tenant, SubscriptionStatus::Active);

        CloseTenant::run($tenant);

        $this->actingAsTenantUser($owner);

        $this->from('http://'.$domain.'/account-closed')
            ->post('http://'.$domain.'/account-closed/reopen')
            ->assertRedirect('http://'.$domain.'/account-closed');

        $tenant->refresh();
        $subscription->refresh();

        $this->assertFalse($tenant->isClosed());
        $this->assertNull($subscription->ends_at);
        $this->assertSame('sub_closure', $subscription->stripe_id);

        $this->get('http://'.$domain.'/team')->assertOk();

        Event::assertDispatched(fn (TenantReopened $event): bool => $event->subscriptionResumed);
    }

    public function test_reopening_after_the_subscription_lapsed_sends_the_owner_to_pick_a_plan(): void
    {
        $this->fakeStripe();

        [$tenant, $domain] = $this->tenant();
        [, $owner] = $this->member($tenant, MembershipRole::Owner);
        $subscription = $this->subscription($tenant, SubscriptionStatus::Active);

        CloseTenant::run($tenant);

        // Past the period the customer had paid for, so there is nothing left
        // to resume.
        $subscription->refresh()->forceFill(['ends_at' => now()->subDay()])->save();

        $this->actingAsTenantUser($owner);

        $this->post('http://'.$domain.'/account-closed/reopen')
            ->assertRedirect(route(RouteNames::tenantsMine()));

        $this->assertFalse($tenant->refresh()->isClosed());
    }

    public function test_closing_is_idempotent(): void
    {
        Event::fake([TenantClosed::class]);

        $this->fakeStripe();

        [$tenant] = $this->tenant();
        $this->member($tenant, MembershipRole::Owner);

        CloseTenant::run($tenant);
        $closedAt = $tenant->refresh()->closed_at;

        $this->travel(1)->hours();

        CloseTenant::run($tenant);

        $this->assertEquals($closedAt, $tenant->refresh()->closed_at);

        Event::assertDispatchedTimes(TenantClosed::class, 1);
    }

    /** Closing is the owner's other exit, so it has to release the account the way a transfer does. */
    public function test_closing_a_workspace_unblocks_account_deletion(): void
    {
        $this->fakeStripe();

        [$tenant] = $this->tenant();
        [$ownerCentral] = $this->member($tenant, MembershipRole::Owner);

        $this->assertFalse(DeleteUserAccount::run($ownerCentral));

        CloseTenant::run($tenant);

        $ownerCentral->refresh();

        $this->assertTrue(DeleteUserAccount::run($ownerCentral));
    }

    public function test_a_webhook_does_not_resurrect_a_closed_tenant(): void
    {
        $this->fakeStripe();

        [$tenant] = $this->tenant();
        $this->member($tenant, MembershipRole::Owner);

        CloseTenant::run($tenant);

        SuspendTenant::run($tenant);
        $this->assertFalse($tenant->refresh()->isSuspended());

        $tenant->forceFill(['suspended_at' => now()])->save();

        RestoreTenant::run($tenant);

        $this->assertTrue($tenant->refresh()->isSuspended());
    }

    /**
     * @return array{0: Tenant, 1: string}
     */
    private function tenant(): array
    {
        $id = 'clo'.substr(uniqid(), -8);

        return [$this->createTenantWithDomain($id, 'Closable Tenant'), $this->tenantDomain($id)];
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

    private function subscription(Tenant $tenant, SubscriptionStatus $status): Subscription
    {
        $subscription = Subscription::query()->create([
            'type' => 'default',
            'stripe_id' => 'sub_closure',
            'stripe_status' => $status->value,
            'stripe_price' => 'price_closure',
            'quantity' => 1,
            'subscribable_id' => $tenant->getKey(),
            'subscribable_type' => Tenant::class,
        ]);

        // `cancel()` reads the period end off the items, not the subscription.
        SubscriptionItem::query()->create([
            'subscription_id' => $subscription->id,
            'stripe_id' => 'si_closure',
            'stripe_product' => 'prod_closure',
            'stripe_price' => 'price_closure',
            'quantity' => 1,
        ]);

        return $subscription;
    }
}
