<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Models\Central;

use App\Models\Central\Tenant;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Nvade\Numerosis\Cache\CacheKeys;
use Nvade\Numerosis\Cache\GlobalCache;
use Nvade\Numerosis\Models\Central\Tenant as PackageTenant;
use Nvade\Numerosis\Tests\Concerns\PinsGlobalCache;
use Nvade\Numerosis\Tests\TestCase;

/**
 * Stancl calls `getCustomColumns()` on every tenant hydration and every save,
 * so a cold static means a schema round-trip on effectively every request that
 * touches a `Tenant`, and one per row on a list page.
 */
class TenantCustomColumnsCacheTest extends TestCase
{
    use PinsGlobalCache;
    use RefreshDatabase;

    public function test_a_cold_static_reads_the_cache_instead_of_the_schema(): void
    {
        $this->pinGlobalCache();

        PackageTenant::flushColumnCache();
        Tenant::getCustomColumns();

        PackageTenant::flushColumnCache();

        $connection = $this->centralDatabase();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        Tenant::getCustomColumns();

        $this->assertEmpty(
            $connection->getQueryLog(),
            'A cold per-request memo went back to the schema instead of the cached listing.'
        );

        // Without the cached listing the same call does query, so the
        // assertion above is not passing on a schema read that never logs.
        GlobalCache::store()->forget(CacheKeys::tenantCustomColumns());
        PackageTenant::flushColumnCache();

        Tenant::getCustomColumns();

        $this->assertNotEmpty($connection->getQueryLog());
    }

    /**
     * A migration is the only thing that changes this schema, so the listener
     * on `MigrationsEnded` is what keeps a host from having to remember.
     */
    public function test_running_migrations_forgets_the_cached_listing(): void
    {
        $this->pinGlobalCache();

        Tenant::getCustomColumns();

        $this->assertNotNull(GlobalCache::store()->get(CacheKeys::tenantCustomColumns()));

        event(new MigrationsEnded('up'));

        $this->assertNull(GlobalCache::store()->get(CacheKeys::tenantCustomColumns()));
    }

    public function test_the_listing_is_not_cached_while_the_table_is_missing(): void
    {
        $this->pinGlobalCache();

        GlobalCache::store()->forget(CacheKeys::tenantCustomColumns());
        PackageTenant::flushColumnCache();

        // A connection with no schema at all stands in for a pre-migration
        // boot, without dropping the table the rest of the suite shares.
        Config::set('database.connections.unmigrated', ['driver' => 'sqlite', 'database' => ':memory:']);
        Config::set('tenancy.database.central_connection', 'unmigrated');

        $this->assertFalse(Schema::connection('unmigrated')->hasTable('tenants'));

        PackageTenant::getCustomColumns();

        $this->assertNull(
            GlobalCache::store()->get(CacheKeys::tenantCustomColumns()),
            'An empty listing was cached before `migrate` ran, so the first real schema is never seen.'
        );
    }

    protected function tearDown(): void
    {
        GlobalCache::store()->forget(CacheKeys::tenantCustomColumns());
        PackageTenant::flushColumnCache();

        parent::tearDown();
    }
}
