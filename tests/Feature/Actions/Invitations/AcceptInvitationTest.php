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
use Nvade\Numerosis\Models\Central\Membership;
use Nvade\Numerosis\Tests\TestCase;
use RuntimeException;

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

    /**
     * The tenant database is a second connection, so a rollback on the central
     * one cannot undo a write made there. When the acceptance transaction
     * fails after the attach, the tenant must not keep a user row for a
     * membership that does not exist centrally.
     *
     * The failure is thrown from a `Membership` created listener, which runs
     * after `MembershipObserver` and inside `AcceptInvitation`'s own
     * transaction. Wrapping the call in a second transaction instead would
     * prove nothing: under `RefreshDatabase`,
     * `Illuminate\Foundation\Testing\DatabaseTransactionsManager`
     * executes after-commit callbacks at level 1 rather than 0, on the
     * assumption that level 1 is the test's own wrapping transaction — so a
     * nested application transaction commits its callbacks early and an outer
     * rollback cannot take them back.
     */
    public function test_a_failed_acceptance_leaves_no_tenant_side_user_row(): void
    {
        $tenant = Tenant::factory()->create(['provisioned_at' => now()]);
        Queue::fake();

        $user = CentralUser::factory()->create();
        $invitation = Invitation::factory()->for($tenant, 'tenant')->create([
            'email' => $user->email,
            'role' => 'member',
        ]);

        // `saved`, not `created`: a listener registered here lands after the
        // observer only for an event the observer has already handled, and the
        // work under test is all in its `created()`.
        Membership::saved(function (): void {
            throw new RuntimeException('Something after the attach failed.');
        });

        try {
            AcceptInvitation::run($invitation, $user);
            $this->fail('Expected the acceptance to fail.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertFalse($user->tenants()->where('tenants.id', $tenant->getKey())->exists());

        $tenant->run(function () use ($user): void {
            $this->assertNull(TenantUser::where('global_id', $user->global_id)->first());
        });
    }

    /**
     * `MemberJoined` fires from `MembershipObserver::created()`, inside the
     * transaction. Without `ShouldDispatchAfterCommit` a queued listener reads
     * a membership row that never commits.
     *
     * Asserted through real listeners rather than `Event::fake()`, whose
     * `fakeEvent()` defers through `Container::getInstance()`'s manager and so
     * answers a different question than the one being asked here.
     */
    public function test_a_failed_acceptance_dispatches_no_member_joined(): void
    {
        $tenant = Tenant::factory()->create(['provisioned_at' => now()]);
        Queue::fake();

        $user = CentralUser::factory()->create();
        $invitation = Invitation::factory()->for($tenant, 'tenant')->create([
            'email' => $user->email,
            'role' => 'member',
        ]);

        $dispatched = [];

        Event::listen(MemberJoined::class, function () use (&$dispatched): void {
            $dispatched[] = MemberJoined::class;
        });

        // `saved`, not `created`: a listener registered here lands after the
        // observer only for an event the observer has already handled, and the
        // work under test is all in its `created()`.
        Membership::saved(function (): void {
            throw new RuntimeException('Something after the attach failed.');
        });

        try {
            AcceptInvitation::run($invitation, $user);
            $this->fail('Expected the acceptance to fail.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame([], $dispatched);
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
