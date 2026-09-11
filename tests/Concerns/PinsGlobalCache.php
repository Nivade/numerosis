<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Concerns;

use Illuminate\Cache\CacheManager;
use Nvade\Numerosis\Cache\GlobalCache;

/**
 * `globalCache` is bound, not singletoned, so under CACHE_STORE=array every
 * global_cache() call builds a fresh ArrayStore and nothing written is ever
 * read back — a test that doesn't pin one manager exercises no real caching
 * at all, and will pass whether or not invalidation is wired correctly.
 * Production runs Redis, where the store is shared; call pinGlobalCache() to
 * make the test behave the same way.
 */
trait PinsGlobalCache
{
    protected function pinGlobalCache(): void
    {
        app()->singleton('globalCache', fn ($app) => new CacheManager($app));

        GlobalCache::flush();
    }
}
