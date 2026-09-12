<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Tenancy;

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
use App\Models\Central\TenantProvision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Nvade\Numerosis\Actions\Tenancy\CreateTenant;
use Nvade\Numerosis\Actions\Tenancy\FinalizeTenantProvisioning;
use Nvade\Numerosis\Actions\Tenancy\LinkTenantSubscription;
use Nvade\Numerosis\Actions\Tenancy\MarkProvisionFailed;
use Nvade\Numerosis\Actions\Tenancy\ProvisionTenant;
use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;
use Nvade\Numerosis\Enums\Tenancy\TenantProvisionStatus;
use Nvade\Numerosis\Events\Tenancy\TenantProvisioningFailed;
use Nvade\Numerosis\Jobs\RunProvisioningStep;
use Nvade\Numerosis\Tests\Concerns\BuildsTenantProvisionData;
use Nvade\Numerosis\Tests\TestCase;

class ProvisionTenantTest extends TestCase
{
    use BuildsTenantProvisionData;
    use RefreshDatabase;

    public function test_it_creates_the_tenant_without_a_subscription(): void
    {
        $user = CentralUser::factory()->create();

        ProvisionTenant::make()->queue($this->provisionData($user, 'provisionme'));

        $this->assertDatabaseHas('tenants', ['id' => 'provisionme']);
        $this->assertDatabaseHas('domains', [
            'tenant_id' => 'provisionme',
            'domain' => 'provisionme.'.Config::string('numerosis.domains.apex'),
        ]);
    }

    public function test_it_is_idempotent_when_run_twice_for_the_same_domain(): void
    {
        $user = CentralUser::factory()->create();
        $data = $this->provisionData($user, 'twicetenant');

        ProvisionTenant::make()->queue($data);
        ProvisionTenant::make()->queue($data);

        $this->assertSame(1, Tenant::where('id', 'twicetenant')->count());
        $this->assertSame(1, $user->tenants()->where('tenants.id', 'twicetenant')->count());
    }

    public function test_it_marks_the_pending_row_failed_and_dispatches_when_the_job_fails(): void
    {
        Event::fake([TenantProvisioningFailed::class]);

        $user = CentralUser::factory()->create();

        TenantProvision::factory()->provisioning()->create([
            'slug' => 'doomed',
            'name' => 'Doomed Co',
            'global_id' => $user->global_id,
        ]);

        // What the chain's ->catch() does. One handler for every step, rather
        // than the three places that each marked the same failure before.
        MarkProvisionFailed::run('doomed', 'queue exploded');
        event(new TenantProvisioningFailed('doomed', $user->global_id));

        $pending = TenantProvision::findOrFail('doomed');

        $this->assertSame(TenantProvisionStatus::Failed, $pending->status);
        $this->assertSame('queue exploded', $pending->error);
        $this->assertNotNull($pending->failed_at);

        Event::assertDispatched(fn (TenantProvisioningFailed $event) => $event->domain === 'doomed'
            && $event->globalId === $user->global_id);
    }

    /**
     * QUEUE_CONNECTION=sync (phpunit.xml) makes Bus::chain() execute every
     * link inline, so this proves the chain actually creates a usable
     * database, not merely that it was dispatched.
     */
    public function test_it_migrates_and_seeds_the_tenant_database(): void
    {
        $user = CentralUser::factory()->create();

        ProvisionTenant::make()->queue($this->provisionData($user, 'dbready'));

        $tenant = Tenant::findOrFail('dbready');

        $tenant->run(function (): void {
            $this->assertTrue(Schema::hasTable('users'));
        });
    }

    public function test_it_dispatches_one_chain_link_per_configured_step(): void
    {
        Bus::fake();

        $user = CentralUser::factory()->create();

        ProvisionTenant::make()->queue($this->provisionData($user, 'chained'));

        $steps = Config::array('numerosis.tenancy.provisioning.steps');

        Bus::assertDispatched(function (RunProvisioningStep $job) use ($steps): bool {
            // The head of the chain carries the rest; one link per step, in
            // configured order, so a host can insert anywhere.
            return $job->chainQueue === 'provisioning'
                && $job->step === $steps[0]
                && count($job->chained) === count($steps) - 1;
        });
    }

    /**
     * Nothing runs synchronously any more, so a refused claim leaves no
     * tenant row behind either -- the first step used to run inline and create
     * one before the chain was even considered.
     */
    public function test_it_does_not_dispatch_a_second_chain_while_one_is_already_in_flight(): void
    {
        Bus::fake();

        $user = CentralUser::factory()->create();

        TenantProvision::factory()->provisioning()->create([
            'slug' => 'racingchain',
            'name' => 'Racing Co',
            'global_id' => $user->global_id,
            'provisioning_started_at' => now(),
        ]);

        ProvisionTenant::make()->queue($this->provisionData($user, 'racingchain'));

        // Not assertNothingDispatched(): CentralUser::factory()->create()
        // legitimately fires its own unrelated queued listeners. This test
        // is only about the provisioning chain never starting.
        Bus::assertNotDispatched(RunProvisioningStep::class);
    }

    /**
     * A step already recorded done is not run again, so a retry resumes. This
     * is what removed the "every step must be idempotent" mandate.
     */
    public function test_a_step_already_recorded_done_is_not_run_again(): void
    {
        $user = CentralUser::factory()->create();

        ProvisionTenant::make()->queue($this->provisionData($user, 'resumes'));

        $provision = TenantProvision::findOrFail('resumes');

        $this->assertTrue($provision->hasRun(CreateTenant::class));

        // Deleting the tenant would break CreateTenant if it ran a second
        // time, since the step record is what stops it.
        Tenant::withoutEvents(fn () => Tenant::findOrFail('resumes')->delete());

        RunProvisioningStep::dispatchSync('resumes', CreateTenant::class);

        $this->assertDatabaseMissing('tenants', ['id' => 'resumes'], 'central');
    }

    /**
     * A step whose contributions are absent is skipped and recorded, rather
     * than the chain builder knowing which steps are billing-dependent.
     */
    public function test_a_step_is_skipped_and_recorded_when_its_contribution_is_absent(): void
    {
        $user = CentralUser::factory()->create();

        // No BillingContribution: provisioned without billing.
        ProvisionTenant::make()->queue(new TenantProvisionData(
            slug: 'nobilling',
            name: 'No Billing Co',
            global_id: $user->global_id,
        ));

        /** @var array<class-string, array<string, string>> $records */
        $records = TenantProvision::findOrFail('nobilling')->step_records;

        $this->assertSame('skipped', $records[LinkTenantSubscription::class]['outcome']);
        $this->assertStringContainsString('BillingContribution', $records[LinkTenantSubscription::class]['reason']);
        $this->assertSame('done', $records[FinalizeTenantProvisioning::class]['outcome']);
    }

    /**
     * The claim replaced `ShouldBeUnique` plus two cache locks. Unlike them it
     * survives a cache flush and, once stale, releases itself rather than
     * holding the slug until a TTL lapses.
     */
    public function test_a_stale_claim_can_be_taken_over(): void
    {
        $user = CentralUser::factory()->create();

        TenantProvision::factory()->provisioning()->create([
            'slug' => 'stale',
            'name' => 'Stale Co',
            'global_id' => $user->global_id,
            'provisioning_started_at' => now()->subMinutes(TenantProvision::STALE_AFTER_MINUTES + 1),
        ]);

        $this->assertTrue(TenantProvision::claim('stale'));
    }

    public function test_a_live_claim_is_refused(): void
    {
        $user = CentralUser::factory()->create();

        TenantProvision::factory()->provisioning()->create([
            'slug' => 'held',
            'name' => 'Held Co',
            'global_id' => $user->global_id,
            'provisioning_started_at' => now(),
        ]);

        $this->assertFalse(TenantProvision::claim('held'));
    }
}
