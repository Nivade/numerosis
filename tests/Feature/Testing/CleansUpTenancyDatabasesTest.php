<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Testing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Nvade\Numerosis\Tests\Support\TestTenant;
use Nvade\Numerosis\Tests\TestCase;

/**
 * `Nvade\Numerosis\Testing\CleansUpTenancyDatabases` is what this suite's own
 * base class runs, so the other ~570 tests already prove it works in the
 * normal path. These cover the parts that only fail *silently*, and only for
 * a host copying the pattern rather than composing the trait:
 *
 * - the tenant-database lookup happening before the central deletes empty the
 *   `tenants` table it reads, and
 * - the trait not needing to win the `beforeApplicationDestroyed()` ordering
 *   race against `RefreshDatabase`'s rollback.
 *
 * Both are invoked directly here (`cleanUpTenancyDatabases()` is protected for
 * exactly this) rather than asserted across two tests: execution order is
 * random, so "the next test sees a clean table" is not a thing a test can
 * observe about the test before it.
 */
class CleansUpTenancyDatabasesTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $hiddenFromTeardown = [];

    /**
     * Hides one database from the clone helper's own record of what it
     * created, leaving the `tenants` table as the only place that name can
     * come from — the position a host without that speedup is in permanently.
     *
     * Narrowed to the named database rather than returning `[]`: the recorded
     * list is process-wide and drained by whichever test tears down next, so
     * discarding it wholesale would leak databases *other* tests created and
     * deleted their own rows for.
     *
     * @return list<string>
     */
    protected function additionalTenantDatabases(): array
    {
        return array_values(array_diff(parent::additionalTenantDatabases(), $this->hiddenFromTeardown));
    }

    public function test_it_deletes_rows_written_through_the_central_connection(): void
    {
        DB::connection('central')->table('users')->insert([
            'global_id' => 'teardown-probe',
            'name' => 'Teardown Probe',
            'email' => 'teardown-probe@example.test',
            'password' => bcrypt('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->cleanUpTenancyDatabases();

        $this->assertSame(
            0,
            DB::connection('central')->table('users')->where('global_id', 'teardown-probe')->count(),
            'A row written on the central connection survived teardown — RefreshDatabase does not roll that connection back.',
        );
    }

    /**
     * The regression this trait exists for, and the one that cost tabellio a
     * milestone of leaked databases: with no clone-helper record to fall back
     * on, the only source for the database name is the tenant row, and the
     * central deletes remove that row. Reversing the two steps drops nothing
     * at all, silently.
     */
    public function test_it_drops_a_tenant_database_named_only_by_a_row_the_central_deletes_remove(): void
    {
        $tenant = TestTenant::provisioned();
        $database = $tenant->database()->getName();
        $this->assertNotNull($database);

        $this->hiddenFromTeardown = [$database];

        $this->assertTrue($this->databaseExists($database), 'Tenant provisioning did not create a physical database, so this test proves nothing.');

        $this->cleanUpTenancyDatabases();

        $this->assertFalse(
            $this->databaseExists($database),
            "Tenant database {$database} outlived teardown: its name was read after the `tenants` row it came from had already been deleted.",
        );
    }

    /**
     * `RefreshDatabase`'s rollback calls `$connection->getPdo()`, which
     * reconnects — against a tenant database this teardown may have just
     * dropped, if the test ended inside tenant context. Ending tenancy first
     * is what makes the trait safe on either side of that callback; without
     * it, teardown throws `Unknown database` with no test-side frame.
     */
    public function test_it_ends_tenancy_before_dropping_anything(): void
    {
        $tenant = TestTenant::provisioned();

        tenancy()->initialize($tenant);

        $this->assertTrue(tenancy()->initialized);

        $this->cleanUpTenancyDatabases();

        $this->assertFalse(tenancy()->initialized, 'Teardown left tenancy initialized, so the default connection still points at a dropped database.');

        // Reconnecting the default connection the way RefreshDatabase's own
        // callback does has to reach the central database, not the dropped one.
        $this->assertSame(0, DB::table('tenants')->count());
    }

    private function databaseExists(string $database): bool
    {
        return DB::connection('central')->selectOne(
            'select schema_name from information_schema.schemata where schema_name = ?',
            [$database],
        ) !== null;
    }
}
