<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Console;

use App\Models\Central\TenantMigrationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\PendingCommand;
use Nvade\Numerosis\Actions\Queries\GetPendingTenantMigrations;
use Nvade\Numerosis\Enums\Tenancy\MigrationRunStatus;
use Nvade\Numerosis\Jobs\RunTenantMigration;
use Nvade\Numerosis\Models\Central\Tenant as BaseTenant;
use Nvade\Numerosis\Tests\Support\FailingTenantMigration;
use Nvade\Numerosis\Tests\Support\TestTenant;
use Nvade\Numerosis\Tests\TestCase;

class MigrateTenantsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        FailingTenantMigration::reset();
    }

    protected function tearDown(): void
    {
        FailingTenantMigration::reset();

        parent::tearDown();
    }

    public function test_it_applies_the_outstanding_migration_to_every_tenant_and_records_each_leg(): void
    {
        $first = TestTenant::provisioned(['id' => 'fleetone']);
        $second = TestTenant::provisioned(['id' => 'fleettwo']);

        $this->addMigrationPath('tenant-extra');

        $this->migrate()->assertSuccessful();

        foreach ([$first, $second] as $tenant) {
            $this->assertTrue($this->tenantHasTable($tenant, 'fleet_probes'));
        }

        $legs = TenantMigrationRun::query()->get();

        $this->assertCount(2, $legs);
        $this->assertSame([MigrationRunStatus::Succeeded, MigrationRunStatus::Succeeded], $legs->pluck('status')->all());
        $this->assertContains('2026_09_17_000000_create_fleet_probes_table', $legs->firstOrFail()->migrations ?? []);
    }

    public function test_the_pending_query_names_the_outstanding_migration_and_nothing_once_it_has_run(): void
    {
        $tenant = TestTenant::provisioned(['id' => 'pendingone']);

        $this->addMigrationPath('tenant-extra');

        $this->assertSame(
            ['2026_09_17_000000_create_fleet_probes_table'],
            GetPendingTenantMigrations::run($tenant),
        );

        $this->migrate()->assertSuccessful();

        $this->assertSame([], GetPendingTenantMigrations::run($tenant));
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $tenant = TestTenant::provisioned(['id' => 'dryrunone']);

        $this->addMigrationPath('tenant-extra');

        $this->migrate('--dry-run')->assertSuccessful();

        $this->assertFalse($this->tenantHasTable($tenant, 'fleet_probes'));
        $this->assertSame(0, TenantMigrationRun::query()->count());
    }

    /**
     * Tenant 40 failing must not stop tenants 41 through 4000, and the failure
     * has to be named rather than swallowed.
     */
    public function test_one_failing_tenant_does_not_stop_the_rest_and_is_reported(): void
    {
        $doomed = TestTenant::provisioned(['id' => 'doomedone']);
        $healthy = TestTenant::provisioned(['id' => 'healthyone']);

        FailingTenantMigration::$tenantId = 'doomedone';

        $this->addMigrationPath('tenant-hostile');
        $this->addMigrationPath('tenant-extra');

        $this->migrate()
            ->expectsOutputToContain('doomedone')
            ->assertFailed();

        $this->assertTrue($this->tenantHasTable($healthy, 'fleet_probes'));
        $this->assertFalse($this->tenantHasTable($doomed, 'fleet_probes'));

        $failed = TenantMigrationRun::query()->where('tenant_id', 'doomedone')->firstOrFail();

        $this->assertSame(MigrationRunStatus::Failed, $failed->status);
        $this->assertStringContainsString('this tenant cannot be migrated', (string) $failed->error);

        $this->assertSame(
            MigrationRunStatus::Succeeded,
            TenantMigrationRun::query()->where('tenant_id', 'healthyone')->firstOrFail()->status,
        );
    }

    /**
     * The classic leak: stancl's own `runForMultiple()` has no `finally`, so a
     * throwing tenant leaves the process pointed at its database and every
     * later tenant migrates into the wrong one.
     */
    public function test_tenancy_is_ended_after_a_failing_tenant(): void
    {
        TestTenant::provisioned(['id' => 'leakyone']);

        FailingTenantMigration::$tenantId = 'leakyone';

        $this->addMigrationPath('tenant-hostile');

        $this->migrate()->assertFailed();

        $this->assertFalse(tenancy()->initialized, 'The run left tenancy initialized against the failing tenant.');
    }

    public function test_resume_skips_the_tenants_the_run_already_finished(): void
    {
        $doomed = TestTenant::provisioned(['id' => 'resumefail']);
        TestTenant::provisioned(['id' => 'resumeok']);

        FailingTenantMigration::$tenantId = 'resumefail';

        $this->addMigrationPath('tenant-hostile');
        $this->addMigrationPath('tenant-extra');

        $this->migrate()->assertFailed();

        $runId = TenantMigrationRun::query()->firstOrFail()->run_id;

        // The second attempt is the fix: the hostile migration stops refusing.
        FailingTenantMigration::reset();

        $this->migrate("--resume={$runId}")->assertSuccessful();

        $this->assertTrue($this->tenantHasTable($doomed, 'fleet_probes'));

        // One row per tenant per run, amended rather than added to.
        $this->assertSame(2, TenantMigrationRun::query()->where('run_id', $runId)->count());

        $this->assertSame(
            MigrationRunStatus::Succeeded,
            TenantMigrationRun::query()->where('tenant_id', 'resumefail')->firstOrFail()->status,
        );
    }

    public function test_queueing_dispatches_one_job_per_tenant_to_the_migrations_queue(): void
    {
        TestTenant::provisioned(['id' => 'queuedone']);
        TestTenant::provisioned(['id' => 'queuedtwo']);

        $this->addMigrationPath('tenant-extra');

        // After provisioning: the chain itself runs through the queue, so a
        // fake installed any earlier leaves both tenants unbuilt.
        Queue::fake();

        $this->migrate('--queue')->assertSuccessful();

        Queue::assertPushedOn(
            Config::string('numerosis.tenancy.migrations.queue'),
            RunTenantMigration::class,
        );

        Queue::assertPushed(RunTenantMigration::class, 2);

        $this->assertSame(2, TenantMigrationRun::query()->where('status', MigrationRunStatus::Pending)->count());
    }

    public function test_pending_skips_a_tenant_that_is_already_up_to_date(): void
    {
        TestTenant::provisioned(['id' => 'uptodate']);

        $this->migrate('--pending')->assertSuccessful();

        $this->assertSame(
            MigrationRunStatus::Skipped,
            TenantMigrationRun::query()->where('tenant_id', 'uptodate')->firstOrFail()->status,
        );
    }

    /**
     * `artisan()` is typed `PendingCommand|int` — it returns the int only once
     * expectations have run. Narrowing here keeps every test a single chained
     * call.
     */
    private function migrate(string ...$options): PendingCommand
    {
        $command = $this->artisan(trim('tenancy:migrate '.implode(' ', $options)));

        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }

    /**
     * The path list is the one `Boot\HostConfig` assembles, so a host's own
     * tenant migrations are included without this command knowing about them.
     */
    private function addMigrationPath(string $directory): void
    {
        $parameters = Config::array('tenancy.migration_parameters');

        /** @var list<string> $paths */
        $paths = $parameters['--path'] ?? [];

        Config::set('tenancy.migration_parameters', [
            ...$parameters,
            '--path' => [...$paths, dirname(__DIR__, 2).'/Support/migrations/'.$directory],
            '--realpath' => true,
        ]);
    }

    private function tenantHasTable(BaseTenant $tenant, string $table): bool
    {
        $exists = $tenant->runHere(fn (): bool => Schema::hasTable($table));

        return $exists === true;
    }
}
