<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Audit;

use App\Models\Central\CentralUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Testing\PendingCommand;
use Nvade\Numerosis\Actions\Queries\ReadActivityLog;
use Nvade\Numerosis\Actions\Tenancy\SuspendTenant;
use Nvade\Numerosis\Enums\Audit\ActivityActor;
use Nvade\Numerosis\Enums\SessionKey;
use Nvade\Numerosis\Enums\Tenancy\MembershipRole;
use Nvade\Numerosis\Features\Admin\ImpersonationFeature;
use Nvade\Numerosis\Features\FeatureRegistry;
use Nvade\Numerosis\Models\Activity;
use Nvade\Numerosis\Models\Central\ImpersonationSession;
use Nvade\Numerosis\Models\Central\Membership;
use Nvade\Numerosis\Tests\Support\TestTenant;
use Nvade\Numerosis\Tests\TestCase;

/**
 * `activity_log` exists on the central connection and once per tenant
 * database. Every assertion here names the central one through
 * {@see ReadActivityLog}, because a query that does not returns plausible rows
 * from whichever connection was default at the time.
 */
class AuditLogCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_suspending_a_tenant_writes_one_entry_against_that_tenant(): void
    {
        $tenant = TestTenant::provisioned(['provisioned_at' => now()]);

        SuspendTenant::run($tenant);

        $entries = ReadActivityLog::forTenant($tenant)
            ->where('description', 'Tenant suspended')
            ->get();

        $this->assertCount(1, $entries);
        $this->assertSame($tenant->id, $entries->first()?->subject_id);
    }

    public function test_a_queued_step_records_the_system_actor_rather_than_nobody(): void
    {
        $tenant = TestTenant::provisioned(['provisioned_at' => now()]);

        SuspendTenant::run($tenant);

        $entry = ReadActivityLog::forTenant($tenant)->where('description', 'Tenant suspended')->first();

        $this->assertInstanceOf(Activity::class, $entry);
        $this->assertSame(ActivityActor::System->value, $entry->getProperty('actor'));
        $this->assertNull($entry->causer_id);
    }

    public function test_a_membership_change_is_logged_centrally_from_inside_tenancy(): void
    {
        $tenant = TestTenant::provisioned(['provisioned_at' => now()]);
        $user = CentralUser::factory()->create();

        tenancy()->initialize($tenant);

        $tenant->users()->attach($user->global_id, ['role' => MembershipRole::Member->value]);

        tenancy()->end();

        $this->assertTrue(
            ReadActivityLog::forTenant($tenant)->where('description', 'Member joined')->exists(),
            'A membership attached inside tenancy wrote its entry into the tenant database.'
        );
    }

    public function test_no_entry_carries_a_credential(): void
    {
        $user = CentralUser::factory()->create();

        $user->forceFill([
            'name' => 'Renamed',
            'two_factor_secret' => 'encrypted-secret',
            'remember_token' => 'a-token',
        ])->save();

        $properties = ReadActivityLog::run()->get()
            ->flatMap(fn (Activity $entry): array => [
                json_encode($entry->properties?->toArray() ?? []),
                json_encode($entry->attribute_changes?->toArray() ?? []),
            ])
            ->implode(' ');

        foreach (['two_factor_secret', 'remember_token', 'password'] as $secret) {
            $this->assertStringNotContainsString($secret, $properties);
        }
    }

    public function test_the_retention_command_keeps_what_is_inside_the_window(): void
    {
        Config::set('activitylog.clean_after_days', 30);

        $tenant = TestTenant::provisioned(['provisioned_at' => now()]);

        SuspendTenant::run($tenant);

        ReadActivityLog::run()->update(['created_at' => now()->subDays(90)]);

        activity()->performedOn($tenant)->log('Recent enough');

        // `run()`, not a bare `artisan()`: a PendingCommand executes on
        // destruct, which is after the assertions below.
        $command = $this->artisan('numerosis:prune-activity-log');
        $this->assertInstanceOf(PendingCommand::class, $command);
        $command->assertSuccessful()->run();

        $remaining = ReadActivityLog::run()->pluck('description');

        $this->assertContains('Recent enough', $remaining->all());
        $this->assertNotContains('Tenant suspended', $remaining->all());
    }

    public function test_one_tenants_entries_are_invisible_to_another(): void
    {
        $first = TestTenant::provisioned(['provisioned_at' => now()]);
        $second = TestTenant::provisioned(['provisioned_at' => now()]);

        SuspendTenant::run($first);

        $this->assertTrue(ReadActivityLog::forTenant($first)->exists());
        $this->assertFalse(
            ReadActivityLog::forTenant($second)->where('description', 'Tenant suspended')->exists()
        );
    }

    /**
     * `GuardImpersonation` sets the causer; the property comes from
     * `Models\Activity`, so an attribute diff written by the impersonated
     * user's own save carries it too.
     */
    public function test_an_entry_written_while_impersonating_names_both_people(): void
    {
        FeatureRegistry::forceForTesting([ImpersonationFeature::class]);

        $tenant = TestTenant::provisioned(['provisioned_at' => now()]);
        $member = CentralUser::factory()->create();
        $staff = CentralUser::factory()->create();

        $session = ImpersonationSession::factory()->redeemed()->create([
            'tenant_id' => $tenant->getKey(),
            'staff_global_id' => $staff->global_id,
            'target_global_id' => $member->global_id,
        ]);

        session([SessionKey::ImpersonationSession->value => $session->id]);

        activity()->causedBy($staff)->performedOn($tenant)->log('Did support work');

        $entry = ReadActivityLog::forTenant($tenant)->where('description', 'Did support work')->first();

        $this->assertInstanceOf(Activity::class, $entry);
        $this->assertSame((string) $staff->id, $entry->causer_id);
        $this->assertSame($member->global_id, $entry->getProperty('impersonated_global_id'));
        $this->assertSame(ActivityActor::Staff->value, $entry->getProperty('actor'));
    }

    /** The screens read `Membership`, which is central and unscoped by route. */
    public function test_a_member_cannot_read_the_activity_of_a_tenant_they_do_not_manage(): void
    {
        $tenant = $this->createTenantWithDomain('audit'.substr(uniqid(), -8), 'Audit Tenant');
        $user = $this->createTenantUser($tenant);

        tenancy()->initialize($tenant);

        $this->assertFalse($user->can('viewActivity', Membership::class));

        tenancy()->end();
    }
}
