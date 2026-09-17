<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Admin;

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\PendingCommand;
use Nvade\Numerosis\Actions\Admin\StartImpersonation;
use Nvade\Numerosis\Actions\Queries\ReadActivityLog;
use Nvade\Numerosis\Database\Seeders\RoleAndPermissionSeeder;
use Nvade\Numerosis\Enums\SessionKey;
use Nvade\Numerosis\Enums\Tenancy\ImpersonationEndReason;
use Nvade\Numerosis\Enums\Tenancy\MembershipRole;
use Nvade\Numerosis\Events\Admin\ImpersonationEnded;
use Nvade\Numerosis\Features\Admin\ImpersonationFeature;
use Nvade\Numerosis\Features\FeatureRegistry;
use Nvade\Numerosis\Models\Central\CentralUser as BaseCentralUser;
use Nvade\Numerosis\Models\Central\ImpersonationSession;
use Nvade\Numerosis\Models\Tenant\User as TenantUser;
use Nvade\Numerosis\Notifications\Tenancy\TenantRestored;
use Nvade\Numerosis\Tests\TestCase;

class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    private bool $permissionsSeeded = false;

    protected function setUp(): void
    {
        FeatureRegistry::forceForTesting([ImpersonationFeature::class]);

        parent::setUp();
    }

    public function test_redeeming_a_token_signs_the_staff_user_in_as_the_member(): void
    {
        [$tenant, $member] = $this->tenantWithMember();

        $url = StartImpersonation::run($tenant, $member->global_id, $this->staff());

        $this->get($url)->assertRedirect($tenant->baseUrl());

        $session = ImpersonationSession::query()->firstOrFail();

        $this->assertNotNull($session->started_at);
        $this->assertTrue($session->isOpen());
        $this->assertSame($session->id, session(SessionKey::ImpersonationSession->value));
    }

    public function test_a_token_is_single_use(): void
    {
        [$tenant, $member] = $this->tenantWithMember();

        $url = StartImpersonation::run($tenant, $member->global_id, $this->staff());

        $this->get($url)->assertRedirect();

        $this->flushSession();

        $this->get($url)->assertNotFound();
    }

    public function test_a_token_is_dead_once_its_window_closes(): void
    {
        [$tenant, $member] = $this->tenantWithMember();

        $url = StartImpersonation::run($tenant, $member->global_id, $this->staff());

        $this->travel(61)->seconds();

        $this->get($url)->assertForbidden();

        $this->assertNull(ImpersonationSession::query()->firstOrFail()->started_at);
    }

    /**
     * The impersonated session is an ordinary tenant session, so the guard's
     * own rule about one session spanning every subdomain still applies.
     */
    public function test_a_second_tenant_does_not_inherit_the_impersonated_session(): void
    {
        [$tenant, $member] = $this->tenantWithMember();
        $other = $this->createTenantWithDomain('otherimp'.substr(uniqid(), -6));

        $url = StartImpersonation::run($tenant, $member->global_id, $this->staff());

        $this->get($url)->assertRedirect();

        $this->get($other->baseUrl().'/team')->assertRedirect();
    }

    public function test_exiting_closes_the_audit_row_and_returns_to_the_panel(): void
    {
        [$tenant, $member] = $this->tenantWithMember();

        $url = StartImpersonation::run($tenant, $member->global_id, $this->staff());

        $this->get($url)->assertRedirect();

        $this->post($tenant->baseUrl().'/impersonate/exit')
            ->assertRedirect();

        $session = ImpersonationSession::query()->firstOrFail();

        $this->assertFalse($session->isOpen());
        $this->assertSame(ImpersonationEndReason::Exit, $session->ended_reason);
        $this->assertNull(session(SessionKey::ImpersonationSession->value));
    }

    /**
     * The staff user's own central session is what makes exit a redirect back
     * to the panel rather than a login screen.
     */
    public function test_the_staff_central_session_survives_the_whole_round_trip(): void
    {
        [$tenant, $member] = $this->tenantWithMember();
        $staff = $this->staff();

        $this->actingAsCentralUser($staff);

        $url = StartImpersonation::run($tenant, $member->global_id, $staff);

        $this->get($url)->assertRedirect();
        $this->post($tenant->baseUrl().'/impersonate/exit')->assertRedirect();

        $this->assertAuthenticatedAs($staff, Config::string('numerosis.auth.guards.central'));
    }

    public function test_an_expired_session_is_ended_on_the_next_request(): void
    {
        Event::fake([ImpersonationEnded::class]);

        [$tenant, $member] = $this->tenantWithMember();

        $url = StartImpersonation::run($tenant, $member->global_id, $this->staff());

        $this->get($url)->assertRedirect();

        $this->travel(61)->minutes();

        $this->get($tenant->baseUrl())->assertOk();

        $session = ImpersonationSession::query()->firstOrFail();

        $this->assertFalse($session->isOpen());
        $this->assertSame(ImpersonationEndReason::Expired, $session->ended_reason);

        Event::assertDispatched(ImpersonationEnded::class);
    }

    public function test_an_abandoned_session_is_closed_by_the_sweep(): void
    {
        [$tenant, $member] = $this->tenantWithMember();

        $url = StartImpersonation::run($tenant, $member->global_id, $this->staff());

        $this->get($url)->assertRedirect();

        $this->travel(61)->minutes();

        $command = $this->artisan('impersonation:end-stale');

        // `run()` is what executes it: PendingCommand otherwise defers to its
        // destructor, which does not fire while this variable is in scope.
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command->assertSuccessful()->run();

        $this->assertFalse(ImpersonationSession::query()->firstOrFail()->isOpen());
    }

    public function test_starting_and_ending_are_both_logged_against_the_staff_user(): void
    {
        [$tenant, $member] = $this->tenantWithMember();
        $staff = $this->staff();

        $url = StartImpersonation::run($tenant, $member->global_id, $staff);

        $this->get($url)->assertRedirect();
        $this->post($tenant->baseUrl().'/impersonate/exit')->assertRedirect();

        // Read through ReadActivityLog, not assertDatabaseHas: both entries name
        // the tenant as their subject and so are written centrally.
        $descriptions = ReadActivityLog::forTenant($tenant)
            ->where('causer_id', $staff->getKey())
            ->pluck('description');

        $this->assertContains("Impersonation of {$member->global_id} in {$tenant->id} started by staff", $descriptions);
        $this->assertContains("Impersonation of {$member->global_id} in {$tenant->id} ended (exit)", $descriptions);
    }

    /**
     * The assertion that makes the audit trail worth having: a write made
     * while impersonating must not read as the customer's own.
     */
    public function test_a_write_made_while_impersonating_is_attributed_to_the_staff_user(): void
    {
        [$tenant, $member] = $this->tenantWithMember();
        $staff = $this->staff();

        $url = StartImpersonation::run($tenant, $member->global_id, $staff);

        $this->get($url)->assertRedirect();

        $this->get($tenant->baseUrl());

        activity()->log('Something the customer appears to have done');

        $this->assertDatabaseHas('activity_log', [
            'description' => 'Something the customer appears to have done',
            'causer_id' => $staff->getKey(),
        ]);
    }

    /**
     * `Notification::fake()` would pass this vacuously: the fake never reaches
     * the sender that fires the event, so the halt is asserted at the
     * dispatcher instead. `NotificationSender` skips a channel whose
     * `until()` returns false.
     */
    public function test_outbound_mail_is_suppressed_while_impersonating(): void
    {
        [$tenant, $member] = $this->tenantWithMember();

        $centralMember = BaseCentralUser::query()->where('global_id', $member->global_id)->firstOrFail();
        $sending = new NotificationSending($centralMember, new TenantRestored($tenant), 'mail');

        $this->assertNull(Event::until($sending), 'Mail is only halted while impersonating.');

        $session = ImpersonationSession::factory()->redeemed()->create([
            'tenant_id' => $tenant->getKey(),
            'staff_global_id' => $this->staff()->global_id,
            'target_global_id' => $member->global_id,
        ]);

        session([SessionKey::ImpersonationSession->value => $session->id]);

        $this->assertFalse(Event::until($sending));
    }

    /**
     * @return array{0: Tenant, 1: TenantUser}
     */
    private function tenantWithMember(): array
    {
        $tenant = $this->createTenantWithDomain('imp'.substr(uniqid(), -8));

        tenancy()->end();

        $central = CentralUser::factory()->create();
        $tenant->users()->attach($central->global_id, ['role' => MembershipRole::Member->value, 'joined_at' => now()]);

        /** @var TenantUser $member */
        $member = $tenant->run(fn (): TenantUser => TenantUser::where('global_id', $central->global_id)->firstOrFail());

        tenancy()->end();

        return [$tenant, $member];
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
