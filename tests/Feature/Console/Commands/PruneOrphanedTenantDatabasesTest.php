<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Console\Commands;

use Nvade\Numerosis\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $this->artisan('tenancy:prune-orphaned-databases', ['--days' => 30, '--force' => true])
            ->assertExitCode(0);

        $this->assertNull(Tenant::find($tenant->id));
    }

    public function test_it_leaves_a_recently_suspended_tenant_alone(): void
    {
        Tenant::unsetEventDispatcher();

        $tenant = Tenant::factory()->create(['suspended_at' => now()->subDays(5)]);

        $this->artisan('tenancy:prune-orphaned-databases', ['--days' => 30, '--force' => true])
            ->assertExitCode(0);

        $this->assertNotNull(Tenant::find($tenant->id));
    }

    public function test_it_leaves_a_non_suspended_tenant_alone(): void
    {
        Tenant::unsetEventDispatcher();

        $tenant = Tenant::factory()->create();

        $this->artisan('tenancy:prune-orphaned-databases', ['--days' => 30, '--force' => true])
            ->assertExitCode(0);

        $this->assertNotNull(Tenant::find($tenant->id));
    }

    public function test_dry_run_does_not_delete(): void
    {
        Tenant::unsetEventDispatcher();

        $tenant = Tenant::factory()->create(['suspended_at' => now()->subDays(31)]);

        $this->artisan('tenancy:prune-orphaned-databases', ['--days' => 30, '--dry-run' => true])
            ->assertExitCode(0);

        $this->assertNotNull(Tenant::find($tenant->id));
    }
}
