<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Invitations;

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
use App\Models\Tenant\User as TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Nvade\Numerosis\Actions\Invitations\AcceptInvitation;
use Nvade\Numerosis\Events\Invitations\InvitationAccepted;
use Nvade\Numerosis\Events\Tenancy\MemberJoined;
use Nvade\Numerosis\Exceptions\Invitations\InvitationAlreadyAccepted;
use Nvade\Numerosis\Exceptions\Invitations\InvitationEmailMismatch;
use Nvade\Numerosis\Exceptions\Invitations\InvitationExpired;
use Nvade\Numerosis\Models\Central\Invitation;
use Nvade\Numerosis\Tests\TestCase;

class AcceptInvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_happy_path_creates_a_membership_and_a_tenant_user_row_without_running_the_queue(): void
    {
        Event::fake([MemberJoined::class, InvitationAccepted::class]);

        $tenant = Tenant::factory()->create(['provisioned_at' => now()]);
        Queue::fake();

        $user = CentralUser::factory()->create();
        $invitation = Invitation::factory()->for($tenant, 'tenant')->create([
            'email' => $user->email,
            'role' => 'member',
        ]);

        $accepted = AcceptInvitation::run($invitation, $user);

        $this->assertTrue($accepted->isAccepted());
        $this->assertTrue($user->tenants()->where('tenants.id', $tenant->getKey())->exists());

        $tenant->run(function () use ($user): void {
            $this->assertNotNull(TenantUser::where('global_id', $user->global_id)->first());
        });

        Event::assertDispatched(MemberJoined::class);
        Event::assertDispatched(InvitationAccepted::class);
    }

    public function test_double_accept_is_idempotent(): void
    {
        Event::fake([MemberJoined::class]);

        $tenant = Tenant::factory()->create(['provisioned_at' => now()]);
        Queue::fake();

        $user = CentralUser::factory()->create();
        $invitation = Invitation::factory()->for($tenant, 'tenant')->create([
            'email' => $user->email,
            'role' => 'member',
        ]);

        AcceptInvitation::run($invitation, $user);

        try {
            AcceptInvitation::run($invitation->fresh(), $user);
            $this->fail('Expected InvitationAlreadyAccepted to be thrown.');
        } catch (InvitationAlreadyAccepted) {
            // expected
        }

        $this->assertSame(1, $user->tenants()->where('tenants.id', $tenant->getKey())->count());
        Event::assertDispatched(MemberJoined::class, 1);
    }

    /**
     * The interleaving two concurrent POSTs produce, reproduced without
     * threads: request A has already loaded the row and holds a stale
     * `accepted_at` of null, and request B commits its acceptance before A
     * reaches its own write.
     *
     * Reading `isAccepted()` off that stale model and stamping afterwards let
     * both through, and the second attach died on
     * `memberships.unique(tenant_id, global_user_id)` with a raw
     * `QueryException`. The conditional `UPDATE ... WHERE accepted_at IS NULL`
     * matches zero rows for the loser instead.
     */
    public function test_a_concurrent_accept_that_lost_the_race_refuses_cleanly(): void
    {
        Event::fake([MemberJoined::class, InvitationAccepted::class]);

        $tenant = Tenant::factory()->create(['provisioned_at' => now()]);
        Queue::fake();

        $user = CentralUser::factory()->create();
        $invitation = Invitation::factory()->for($tenant, 'tenant')->create([
            'email' => $user->email,
            'role' => 'member',
        ]);

        // Request A's copy, loaded before anyone accepted.
        $stale = $invitation->fresh();
        $this->assertNotNull($stale);
        $this->assertFalse($stale->isAccepted());

        // Request B commits in between.
        AcceptInvitation::run($invitation->fresh(), $user);

        $this->expectException(InvitationAlreadyAccepted::class);

        try {
            AcceptInvitation::run($stale, $user);
        } finally {
            $this->assertSame(1, $user->tenants()->where('tenants.id', $tenant->getKey())->count());
            Event::assertDispatched(MemberJoined::class, 1);
            Event::assertDispatched(InvitationAccepted::class, 1);
        }
    }

    public function test_an_expired_invitation_refuses_without_creating_a_membership(): void
    {
        $tenant = Tenant::factory()->create();
        Queue::fake();

        $user = CentralUser::factory()->create();
        $invitation = Invitation::factory()->for($tenant, 'tenant')->expired()->create([
            'email' => $user->email,
        ]);

        $this->expectException(InvitationExpired::class);

        try {
            AcceptInvitation::run($invitation, $user);
        } finally {
            $this->assertFalse($user->tenants()->where('tenants.id', $tenant->getKey())->exists());
        }
    }

    public function test_a_mismatched_email_is_refused_without_creating_a_membership(): void
    {
        $tenant = Tenant::factory()->create();
        Queue::fake();

        $user = CentralUser::factory()->create(['email' => 'actual-owner@example.com']);
        $invitation = Invitation::factory()->for($tenant, 'tenant')->create([
            'email' => 'someone-else@example.com',
        ]);

        $this->expectException(InvitationEmailMismatch::class);

        try {
            AcceptInvitation::run($invitation, $user);
        } finally {
            $this->assertFalse($user->tenants()->where('tenants.id', $tenant->getKey())->exists());
        }
    }
}
