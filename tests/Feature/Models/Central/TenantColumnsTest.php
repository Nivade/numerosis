<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Models\Central;

use App\Models\Central\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
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

        foreach (['stripe_id', 'pm_type', 'pm_last_four', 'trial_ends_at', 'provisioned_at', 'created_at', 'updated_at'] as $column) {
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

    // Process-wide static state — reset so it doesn't bleed into other tests.
    protected function tearDown(): void
    {
        (function (): void {
            static::$additionalCustomColumns = [];
        })->call(new Tenant);

        parent::tearDown();
    }

    public function test_a_consumer_can_register_additional_custom_columns(): void
    {
        Tenant::addCustomColumns(['favorite_color']);

        $this->assertContains('favorite_color', Tenant::getCustomColumns());
        $this->assertContains('provisioned_at', Tenant::getCustomColumns());
    }

    public function test_registering_the_same_column_twice_does_not_duplicate_it(): void
    {
        Tenant::addCustomColumns(['favorite_color']);
        Tenant::addCustomColumns(['favorite_color']);

        $this->assertSame(1, array_count_values(Tenant::getCustomColumns())['favorite_color']);
    }
}
