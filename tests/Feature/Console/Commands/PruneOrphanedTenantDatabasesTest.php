<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Console\Commands;

use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Nvade\Numerosis\Tests\TestCase;

/**
 * Covers only the suspended-tenant half added in this change; the
 * orphaned-database half is pre-existing and untouched.
 *
 * The command's pre-existing orphan-database phase always runs first,
 * unconditionally, and drops anything matching `{prefix}%` with no
 * matching Tenant row — which includes CloneTenantSchema's shared
 * `tenantphpunittemplate` database, since nothing gives it a Tenant row.
 * Pointing the prefix at something nothing matches makes that phase a
 * guaranteed no-op, so these tests exercise only the suspended-tenant
 * phase under test.
 */
class PruneOrphanedTenantDatabasesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenancy.database.prefix' => 'unused-prefix-for-orphan-scan-']);
    }

    public function test_it_deletes_a_tenant_suspended_past_the_cutoff(): void
    {
        Tenant::unsetEventDispatcher();

        $tenant = Tenant::factory()->create(['suspended_at' => now()->subDays(31)]);

        $this->pruneOrphanedDatabases(['--days' => 30, '--force' => true])
            ->assertExitCode(0);

        $this->assertNull(Tenant::find($tenant->id));
    }

    public function test_it_leaves_a_recently_suspended_tenant_alone(): void
    {
        Tenant::unsetEventDispatcher();

        $tenant = Tenant::factory()->create(['suspended_at' => now()->subDays(5)]);

        $this->pruneOrphanedDatabases(['--days' => 30, '--force' => true])
            ->assertExitCode(0);

        $this->assertNotNull(Tenant::find($tenant->id));
    }

    public function test_it_leaves_a_non_suspended_tenant_alone(): void
    {
        Tenant::unsetEventDispatcher();

        $tenant = Tenant::factory()->create();

        $this->pruneOrphanedDatabases(['--days' => 30, '--force' => true])
            ->assertExitCode(0);

        $this->assertNotNull(Tenant::find($tenant->id));
    }

    public function test_it_leaves_a_closed_tenant_alone_while_purging_is_off(): void
    {
        Tenant::unsetEventDispatcher();

        $tenant = Tenant::factory()->create(['closed_at' => now()->subDays(60)]);

        $this->pruneOrphanedDatabases(['--days' => 30, '--force' => true])
            ->expectsOutputToContain('Purging closed tenants is off')
            ->assertExitCode(0);

        $this->assertNotNull(Tenant::find($tenant->id));
    }

    public function test_it_deletes_a_closed_tenant_only_once_its_recovery_window_has_passed(): void
    {
        Tenant::unsetEventDispatcher();

        config(['numerosis.tenancy.closure.purge_closed' => true]);

        // The subject here is the window, not the backup interlock, and these
        // factory tenants have no database to snapshot.
        config(['numerosis.tenancy.backup.before_purge' => false]);

        $inside = Tenant::factory()->create(['closed_at' => now()->subDays(10)]);
        $outside = Tenant::factory()->create(['closed_at' => now()->subDays(31)]);

        $this->pruneOrphanedDatabases(['--days' => 30, '--force' => true])
            ->assertExitCode(0);

        $this->assertNotNull(Tenant::find($inside->id));
        $this->assertNull(Tenant::find($outside->id));
    }

    /**
     * The interlock from `tenant-backup-restore.md`: a purge takes one final
     * snapshot, and a tenant it cannot snapshot keeps its database.
     */
    public function test_it_refuses_to_purge_a_closed_tenant_whose_final_backup_fails(): void
    {
        Tenant::unsetEventDispatcher();

        config(['numerosis.tenancy.closure.purge_closed' => true]);

        // No database was ever created for a bare factory tenant, so the
        // backup throws — which is exactly the case worth keeping.
        $tenant = Tenant::factory()->create(['closed_at' => now()->subDays(31)]);

        $this->pruneOrphanedDatabases(['--days' => 30, '--force' => true])
            ->expectsOutputToContain('final backup failed')
            ->assertExitCode(1);

        $this->assertNotNull(Tenant::find($tenant->id));
    }

    /** The suspended cohort would otherwise delete it whatever `purge_closed` says. */
    public function test_a_closed_tenant_is_not_reached_through_the_suspended_cohort(): void
    {
        Tenant::unsetEventDispatcher();

        $tenant = Tenant::factory()->create([
            'closed_at' => now()->subDays(60),
            'suspended_at' => now()->subDays(60),
        ]);

        $this->pruneOrphanedDatabases(['--days' => 30, '--force' => true])
            ->assertExitCode(0);

        $this->assertNotNull(Tenant::find($tenant->id));
    }

    /** With no `--days`, the closure grace period is the one number both cohorts read. */
    public function test_the_cutoff_defaults_to_the_configured_grace_period(): void
    {
        Tenant::unsetEventDispatcher();

        config(['numerosis.tenancy.closure.grace_days' => 5]);

        $tenant = Tenant::factory()->create(['suspended_at' => now()->subDays(6)]);

        $this->pruneOrphanedDatabases(['--force' => true])->assertExitCode(0);

        $this->assertNull(Tenant::find($tenant->id));
    }

    public function test_dry_run_does_not_delete(): void
    {
        Tenant::unsetEventDispatcher();

        $tenant = Tenant::factory()->create(['suspended_at' => now()->subDays(31)]);

        $this->pruneOrphanedDatabases(['--days' => 30, '--dry-run' => true])
            ->assertExitCode(0);

        $this->assertNotNull(Tenant::find($tenant->id));
    }

    /**
     * `artisan()` is typed `PendingCommand|int` — it returns the int only once
     * expectations have been run. Narrowing here keeps every test a single
     * chained call without a baseline entry.
     *
     * @param  array<string, mixed>  $options
     */
    private function pruneOrphanedDatabases(array $options): PendingCommand
    {
        $command = $this->artisan('tenancy:prune-orphaned-databases', $options);

        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }
}
