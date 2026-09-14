<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Cache;

use App\Models\Central\CentralUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Actions\Queries\FindUserByGlobalId;
use Nvade\Numerosis\Cache\CacheTtl;
use Nvade\Numerosis\Cache\GlobalCache;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Tests\Concerns\PinsGlobalCache;
use Nvade\Numerosis\Tests\TestCase;

/**
 * `numerosis.cache.ttl` and `numerosis.cache.store` are the host's controls
 * over every global key. A null TTL has to bypass the store rather than write
 * a zero-second entry, which several drivers read as "forever".
 */
class CacheConfigTest extends TestCase
{
    use PinsGlobalCache;
    use RefreshDatabase;

    public function test_a_null_ttl_means_do_not_cache(): void
    {
        Config::set('numerosis.cache.ttl.user_model');

        $this->assertNull(CacheTtl::userModel());
    }

    public function test_a_null_ttl_queries_on_every_call(): void
    {
        $this->pinGlobalCache();

        $user = CentralUser::factory()->create();

        Config::set('numerosis.cache.ttl.user_model');

        FindUserByGlobalId::run($user->global_id, Context::Central);

        $connection = $this->centralDatabase();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        FindUserByGlobalId::run($user->global_id, Context::Central);

        $this->assertNotEmpty(
            $connection->getQueryLog(),
            'A null TTL must skip the cache, not write an entry that never expires.'
        );
    }

    public function test_a_configured_ttl_caches(): void
    {
        $this->pinGlobalCache();

        $user = CentralUser::factory()->create();

        Config::set('numerosis.cache.ttl.user_model', 3600);

        FindUserByGlobalId::run($user->global_id, Context::Central);

        $connection = $this->centralDatabase();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        FindUserByGlobalId::run($user->global_id, Context::Central);

        $this->assertEmpty($connection->getQueryLog());
    }

    public function test_the_configured_store_is_the_one_written_to(): void
    {
        $this->pinGlobalCache();

        Config::set('cache.stores.numerosis_probe', ['driver' => 'array']);
        Config::set('numerosis.cache.store', 'numerosis_probe');
        GlobalCache::flush();

        GlobalCache::store()->put('probe-key', 'probe-value', 60);

        $manager = resolve('globalCache');

        $this->assertSame('probe-value', $manager->store('numerosis_probe')->get('probe-key'));
        $this->assertNull($manager->store(null)->get('probe-key'));
    }

    /** The memo carries the store name, or a changed setting never takes effect. */
    public function test_changing_the_store_rebuilds_the_memo(): void
    {
        $this->pinGlobalCache();

        Config::set('cache.stores.numerosis_probe', ['driver' => 'array']);

        $before = GlobalCache::store();

        Config::set('numerosis.cache.store', 'numerosis_probe');

        $this->assertNotSame($before, GlobalCache::store());
    }
}
