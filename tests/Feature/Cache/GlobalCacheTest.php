<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Cache;

use Illuminate\Cache\CacheManager;
use Nvade\Numerosis\Cache\GlobalCache;
use Nvade\Numerosis\Tests\TestCase;

/**
 * `globalCache` is a `bind`, so resolving it built a fresh `CacheManager` and
 * a fresh store on every call. The callers are hot — `Authenticate` reaches
 * `GetTenantsByGlobalId` on every authenticated tenant request — and a
 * five-tenant list page built roughly sixteen managers.
 */
class GlobalCacheTest extends TestCase
{
    public function test_the_store_is_resolved_once(): void
    {
        $this->assertSame(GlobalCache::store(), GlobalCache::store());
    }

    public function test_flushing_lets_a_rebound_binding_through(): void
    {
        $before = GlobalCache::store();

        $this->app?->singleton('globalCache', fn ($app) => new CacheManager($app));
        GlobalCache::flush();

        $this->assertNotSame($before, GlobalCache::store());
    }

    /**
     * The memo is keyed on the container, so a store built for a previous
     * application — an Octane worker's, or the one a test threw away — is
     * never handed back.
     */
    public function test_a_new_application_gets_its_own_store(): void
    {
        $before = GlobalCache::store();

        $this->refreshApplication();

        $this->assertNotSame($before, GlobalCache::store());
    }
}
