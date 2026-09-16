<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Models\Central;

use App\Models\Central\Tenant;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvade\Numerosis\Models\Central\Tenant as PackageTenant;
use Nvade\Numerosis\Tests\TestCase;

class TenantColumnsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * VirtualColumn folds every attribute outside getCustomColumns() into the
     * `data` JSON column, and its default is `['id']` alone. The model reads
     * those back transparently, so the only thing that catches a missing entry
     * is looking at the row itself.
     */
    public function test_real_columns_are_not_folded_into_the_data_column(): void
    {
        $tenant = Tenant::create([
            'id' => 'columns-'.uniqid(),
            'name' => 'Column Test',
            'provisioned_at' => now(),
        ]);

        // Quietly, so the Stripe customer sync that rides on the saved event
        // does not reach out to the API for an id that only exists here.
        $tenant->stripe_id = 'cus_columntest';
        $tenant->saveQuietly();

        $row = DB::connection(Config::string('tenancy.database.central_connection', 'central'))
            ->table('tenants')
            ->where('id', $tenant->id)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('cus_columntest', $row->stripe_id);
        $this->assertNotNull($row->provisioned_at);
        $this->assertNotNull($row->created_at);

        /** @var array<string, mixed> $data */
        $data = json_decode((string) $row->data, true) ?: [];

        foreach (['stripe_id', 'pm_type', 'pm_last_four', 'trial_ends_at', 'provisioned_at', 'closed_at', 'created_at', 'updated_at'] as $column) {
            $this->assertArrayNotHasKey($column, $data, "`{$column}` is a real column but was written to `data`.");
        }

        // `name` has no column of its own, so it belongs in `data`.
        $this->assertSame('Column Test', $data['name'] ?? null);
    }

    /**
     * `provisioned_at` is the tenant readiness flag, and the UI filters on it in
     * SQL — which only works if the column, not the JSON blob, holds the value.
     */
    public function test_provisioned_at_is_queryable_in_sql(): void
    {
        $ready = Tenant::create(['id' => 'ready-'.uniqid(), 'provisioned_at' => now()]);
        $pending = Tenant::create(['id' => 'pending-'.uniqid()]);

        $provisioned = Tenant::query()->whereNotNull('provisioned_at')->pluck('id');

        $this->assertTrue($provisioned->contains($ready->id));
        $this->assertFalse($provisioned->contains($pending->id));
    }

    /** `closed_at` decides the recovery window, and the prune command reads it in SQL. */
    public function test_closed_at_is_queryable_in_sql(): void
    {
        $closed = Tenant::create(['id' => 'closed-'.uniqid(), 'closed_at' => now()]);
        $open = Tenant::create(['id' => 'open-'.uniqid()]);

        $ids = Tenant::query()->whereNotNull('closed_at')->pluck('id');

        $this->assertTrue($ids->contains($closed->id));
        $this->assertFalse($ids->contains($open->id));
    }

    public function test_a_column_added_by_migration_is_recognised_without_registration(): void
    {
        $this->addColumn('favorite_color');

        $this->assertContains('favorite_color', Tenant::getCustomColumns());
        $this->assertContains('provisioned_at', Tenant::getCustomColumns());
    }

    public function test_a_column_added_by_migration_is_written_to_its_own_column(): void
    {
        $this->addColumn('favorite_color');

        $tenant = Tenant::create(['id' => 'introspect-'.uniqid()]);

        $tenant->setAttribute('favorite_color', 'green');
        $tenant->saveQuietly();

        $row = DB::connection($this->centralConnection())
            ->table('tenants')
            ->where('id', $tenant->id)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('green', $row->favorite_color);

        /** @var array<string, mixed> $data */
        $data = json_decode((string) $row->data, true) ?: [];

        $this->assertArrayNotHasKey('favorite_color', $data);
    }

    /**
     * The `data` column is the store the rest are folded into, so naming it as
     * a real column would make VirtualColumn overwrite the blob with itself.
     */
    public function test_the_data_column_is_never_a_custom_column(): void
    {
        $this->assertNotContains('data', Tenant::getCustomColumns());
    }

    private function centralConnection(): string
    {
        return Config::string('tenancy.database.central_connection', 'central');
    }

    private function addColumn(string $column): void
    {
        Schema::connection($this->centralConnection())
            ->table('tenants', function (Blueprint $table) use ($column): void {
                $table->string($column)->nullable();
            });

        PackageTenant::flushColumnCache();
    }

    /**
     * The added column and the memoized listing both outlive this test — DDL
     * commits through RefreshDatabase's open transaction, and the cache is a
     * process-lifetime static.
     */
    protected function tearDown(): void
    {
        $schema = Schema::connection($this->centralConnection());

        if ($schema->hasTable('tenants') && $schema->hasColumn('tenants', 'favorite_color')) {
            $schema->table('tenants', function (Blueprint $table): void {
                $table->dropColumn('favorite_color');
            });
        }

        PackageTenant::flushColumnCache();

        parent::tearDown();
    }
}
