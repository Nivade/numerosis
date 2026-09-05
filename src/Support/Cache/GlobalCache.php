<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Support\Cache;

use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Repository;
use RuntimeException;

/**
 * Typed accessor for stancl's `globalCache` binding, which every key
 * {@see CacheKeys} documents as *global* is read and invalidated through. The
 * binding is not tenant-scoped and carries no tenant tag or prefix, so
 * anything derived from a tenant database must put the tenant in its own key.
 * It is a `bind`, so each call constructs a fresh manager.
 */
final class GlobalCache
{
    private function __construct() {}

    /**
     * The `Factory` branch is the one that runs: stancl binds `globalCache` to
     * an `Illuminate\Cache\CacheManager`, which implements `Factory` and never
     * `Repository`.
     */
    public static function store(): Repository
    {
        $cache = app('globalCache');

        if ($cache instanceof Repository) {
            return $cache;
        }

        if ($cache instanceof Factory) {
            return $cache->store();
        }

        throw new RuntimeException(
            'The [globalCache] container binding must resolve to a cache '
            .'repository or factory; got '.get_debug_type($cache).'.'
        );
    }
}
