<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Actions\Tenancy;

use App\Models\Central\CentralUser;
use App\Models\Central\PendingTenantProvision;
use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Lorisleiva\Actions\Decorators\JobDecorator;
use Nvade\Numerosis\Actions\Tenancy\FinalizeTenantProvisioning;
use Nvade\Numerosis\Actions\Tenancy\ProvisionTenant;
use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;
use Nvade\Numerosis\Data\Tenancy\TenantRegistrationData;
use Nvade\Numerosis\Enums\Billing\BillingCycle;
use Nvade\Numerosis\Enums\Tenancy\TenantProvisionStatus;
use Nvade\Numerosis\Events\Tenancy\TenantProvisioningFailed;
use Nvade\Numerosis\Tests\TestCase;
use RuntimeException;
use Stancl\Tenancy\Jobs\CreateDatabase;

class ProvisionTenantTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_the_tenant_without_a_subscription(): void
    {
        $user = CentralUser::factory()->create();

        ProvisionTenant::run($this->provisionData($user, 'provisionme'));

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

        ProvisionTenant::run($data);
        ProvisionTenant::run($data);

        $this->assertSame(1, Tenant::where('id', 'twicetenant')->count());
        $this->assertSame(1, $user->tenants()->where('tenants.id', 'twicetenant')->count());
    }

    public function test_it_marks_the_pending_row_failed_and_broadcasts_when_the_job_fails(): void
    {
        Event::fake([TenantProvisioningFailed::class]);

        $user = CentralUser::factory()->create();
        $data = $this->provisionData($user, 'doomed');

        PendingTenantProvision::create([
            'domain' => 'doomed',
            'company_name' => 'Doomed Co',
            'global_id' => $user->global_id,
            'status' => TenantProvisionStatus::Provisioning,
        ]);

        ProvisionTenant::make()->jobFailed(new RuntimeException('queue exploded'), $data);

        $pending = PendingTenantProvision::find('doomed');

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

        ProvisionTenant::run($this->provisionData($user, 'dbready'));

        $tenant = Tenant::findOrFail('dbready');

        $tenant->run(function (): void {
            $this->assertTrue(Schema::hasTable('users'));
        });
    }

    public function test_it_dispatches_a_provisioning_chain_on_the_provisioning_queue(): void
    {
        Bus::fake();

        $user = CentralUser::factory()->create();

        ProvisionTenant::run($this->provisionData($user, 'chained'));

        Bus::assertDispatched(function (CreateDatabase $job) {
            if ($job->chainQueue !== 'provisioning' || $job->chained === []) {
                return false;
            }

            $lastLink = unserialize((string) end($job->chained));

            return $lastLink instanceof JobDecorator
                && $lastLink->decorates(FinalizeTenantProvisioning::class);
        });
    }

    public function test_it_does_not_dispatch_a_second_chain_while_one_is_already_in_flight(): void
    {
        Bus::fake();

        $user = CentralUser::factory()->create();
        $domain = 'racingchain';

        Cache::lock("tenant-chain:{$domain}", 900)->get();

        ProvisionTenant::run($this->provisionData($user, $domain));

        // 'central' explicit: see the comment in CreateTenantTest for why.
        $this->assertDatabaseHas('tenants', ['id' => $domain], 'central');

        // Not assertNothingDispatched(): CentralUser::factory()->create()
        // legitimately fires its own unrelated queued listeners. This test
        // is only about the provisioning chain never starting.
        Bus::assertNotDispatched(CreateDatabase::class);
    }

    public function test_it_dispatches_the_database_segment_for_a_retry_with_no_physical_database(): void
    {
        Bus::fake();

        $user = CentralUser::factory()->create();
        $domain = 'retryme';

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $domain,
            'name' => 'Retry Co',
            'registration_date' => now(),
            'created_by' => $user->global_id,
        ]));

        ProvisionTenant::run($this->provisionData($user, $domain));

        Bus::assertDispatched(CreateDatabase::class);
    }

    public function test_it_is_unique_per_domain(): void
    {
        $user = CentralUser::factory()->create();

        $this->assertSame(
            'acme',
            ProvisionTenant::make()->getJobUniqueId($this->provisionData($user, 'acme')),
        );
    }

    private function provisionData(CentralUser $user, string $domain): TenantProvisionData
    {
        return new TenantProvisionData(
            registration: TenantRegistrationData::from([
                'company_name' => 'Test Company',
                'domain' => $domain,
                'billing_cycle' => BillingCycle::Monthly,
                'global_id' => $user->global_id,
            ]),
            centralUserId: (string) $user->id,
        );
    }
}
