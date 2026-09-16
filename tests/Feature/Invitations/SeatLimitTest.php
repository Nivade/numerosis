<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Invitations;

use App\Models\Central\CentralUser;
use App\Models\Central\PaymentPlan;
use App\Models\Central\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Nvade\Numerosis\Actions\Invitations\AcceptInvitation;
use Nvade\Numerosis\Actions\Invitations\SendInvitation;
use Nvade\Numerosis\Actions\Queries\GetTenantSeatUsage;
use Nvade\Numerosis\Data\Invitations\InvitationData;
use Nvade\Numerosis\Enums\Tenancy\MembershipRole;
use Nvade\Numerosis\Exceptions\Invitations\SeatLimitReached;
use Nvade\Numerosis\Models\Central\CentralUser as BaseCentralUser;
use Nvade\Numerosis\Models\Central\Invitation;
use Nvade\Numerosis\Models\Central\PaymentPlan as BasePaymentPlan;
use Nvade\Numerosis\Models\Central\Tenant as BaseTenant;
use Nvade\Numerosis\Numerosis;
use Nvade\Numerosis\Tests\Support\TestTenant;
use Nvade\Numerosis\Tests\TestCase;

/**
 * `SeatLimitPlanPolicy` was consulted only when a plan was chosen, so a capped
 * plan admitted an unbounded number of members as long as they arrived by
 * invitation.
 */
class SeatLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_accept_at_the_cap_is_refused_and_the_row_stays_claimable(): void
    {
        $tenant = TestTenant::provisioned(['provisioned_at' => now()]);

        Queue::fake();
        $plan = $this->subscribeTo($tenant, maxUsers: 1);

        $this->addMember($tenant);

        $user = CentralUser::factory()->create();
        $invitation = Invitation::factory()->for($tenant, 'tenant')->create(['email' => $user->email]);

        try {
            AcceptInvitation::run($invitation, $user);
            $this->fail('Expected SeatLimitReached to be thrown.');
        } catch (SeatLimitReached) {
            // expected
        }

        $this->assertFalse($invitation->fresh()?->isAccepted());
        $this->assertFalse($user->tenants()->where('tenants.id', $tenant->getKey())->exists());

        $plan->update(['metadata' => ['options' => ['max_users' => 5]]]);

        AcceptInvitation::run($invitation->refresh(), $user);

        $this->assertTrue($user->tenants()->where('tenants.id', $tenant->getKey())->exists());
    }

    /**
     * The case the unfixed code got wrong: invitations outlive the plan they
     * were issued under, so counting at accept time is the only count a
     * downgrade cannot outrun.
     */
    public function test_a_downgrade_stops_the_accepts_of_invitations_issued_under_the_larger_plan(): void
    {
        $tenant = TestTenant::provisioned(['provisioned_at' => now()]);

        Queue::fake();
        Notification::fake();
        $plan = $this->subscribeTo($tenant, maxUsers: 10);
        $inviter = CentralUser::factory()->create();

        $invitees = [];

        for ($i = 0; $i < 10; $i++) {
            $email = "invitee-{$i}@example.com";
            $invitees[] = CentralUser::factory()->create(['email' => $email]);

            SendInvitation::run($tenant, new InvitationData($email, MembershipRole::Member), $inviter);
        }

        $plan->update(['metadata' => ['options' => ['max_users' => 3]]]);

        $accepted = 0;

        foreach ($invitees as $invitee) {
            $invitation = Invitation::where('tenant_id', $tenant->getKey())
                ->where('email', $invitee->email)
                ->firstOrFail();

            try {
                AcceptInvitation::run($invitation, $invitee);
                $accepted++;
            } catch (SeatLimitReached) {
                // expected once the plan is full
            }
        }

        $this->assertSame(3, $accepted);
        $this->assertSame(3, $tenant->users()->count());
    }

    public function test_resending_an_invitation_does_not_consume_an_extra_seat(): void
    {
        $tenant = TestTenant::provisioned(['provisioned_at' => now()]);

        Queue::fake();
        Notification::fake();
        $this->subscribeTo($tenant, maxUsers: 2);

        $inviter = $this->addMember($tenant);
        $invited = new InvitationData('resent@example.com', MembershipRole::Member);

        SendInvitation::run($tenant, $invited, $inviter);
        SendInvitation::run($tenant, $invited, $inviter);

        $this->assertSame(1, Invitation::where('tenant_id', $tenant->getKey())->pending()->count());

        $this->expectException(SeatLimitReached::class);

        SendInvitation::run($tenant, new InvitationData('one-too-many@example.com', MembershipRole::Member), $inviter);
    }

    public function test_a_plan_without_a_seat_limit_admits_members_without_limit(): void
    {
        $tenant = TestTenant::provisioned(['provisioned_at' => now()]);

        Queue::fake();
        $this->subscribeTo($tenant, maxUsers: null);

        for ($i = 0; $i < 3; $i++) {
            $user = CentralUser::factory()->create();
            $invitation = Invitation::factory()->for($tenant, 'tenant')->create(['email' => $user->email]);

            AcceptInvitation::run($invitation, $user);
        }

        $this->assertSame(3, $tenant->users()->count());
        $this->assertNull(GetTenantSeatUsage::run($tenant)->limit);
    }

    public function test_the_count_includes_pending_invitations_and_excludes_expired_ones(): void
    {
        $tenant = TestTenant::provisioned(['provisioned_at' => now()]);

        Queue::fake();
        $this->subscribeTo($tenant, maxUsers: 5);
        $this->addMember($tenant);

        Invitation::factory()->for($tenant, 'tenant')->create();
        Invitation::factory()->for($tenant, 'tenant')->expired()->create();
        Invitation::factory()->for($tenant, 'tenant')->accepted()->create();

        $usage = GetTenantSeatUsage::run($tenant);

        $this->assertSame(1, $usage->members);
        $this->assertSame(1, $usage->pendingInvitations);
        $this->assertSame(2, $usage->used());
        $this->assertSame(5, $usage->limit);
    }

    private function subscribeTo(BaseTenant $tenant, ?int $maxUsers): BasePaymentPlan
    {
        $plan = PaymentPlan::factory()->create([
            'metadata' => $maxUsers === null ? [] : ['options' => ['max_users' => $maxUsers]],
        ]);

        Subscription::factory()->create([
            'subscribable_id' => $tenant->getKey(),
            'subscribable_type' => Numerosis::model(BaseTenant::class),
            'payment_plan_id' => $plan->getKey(),
        ]);

        return $plan;
    }

    private function addMember(BaseTenant $tenant): BaseCentralUser
    {
        $user = CentralUser::factory()->create();

        $invitation = Invitation::factory()->for($tenant, 'tenant')->create([
            'email' => $user->email,
            'role' => MembershipRole::Admin->value,
        ]);

        AcceptInvitation::run($invitation, $user);

        return $user;
    }
}
