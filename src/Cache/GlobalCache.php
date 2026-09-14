<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Cache;

use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Container\Container;
use RuntimeException;

/**
 * Typed accessor for stancl's `globalCache` binding, which every key
 * {@see CacheKeys} documents as *global* is read and invalidated through. The
 * binding is not tenant-scoped and carries no tenant tag or prefix, so
 * anything derived from a tenant database must put the tenant in its own key.
 */
final class GlobalCache
{
    /**
     * The resolved store, and the container it was resolved from.
     *
     * Memoized because `globalCache` is a `bind`: every resolve built a fresh
     * `CacheManager`, and a five-tenant list page built roughly sixteen of
     * them. Keyed on the container so an Octane worker never reads a store
     * built for a different application.
     */
    private static ?Repository $store = null;

    private static ?Container $resolvedFor = null;

    private function __construct() {}

    /**
     * The `Factory` branch is the one that runs: stancl binds `globalCache` to
     * an `Illuminate\Cache\CacheManager`, which implements `Factory` and never
     * `Repository`.
     */
    public static function store(): Repository
    {
        $container = app();

        if (self::$store !== null && self::$resolvedFor === $container) {
            return self::$store;
        }

        $cache = $container->make('globalCache');

        $store = match (true) {
            $cache instanceof Repository => $cache,
            $cache instanceof Factory => $cache->store(),
            default => throw new RuntimeException(
                'The [globalCache] container binding must resolve to a cache '
                .'repository or factory; got '.get_debug_type($cache).'.'
            ),
        };

        self::$resolvedFor = $container;

        return self::$store = $store;
    }

    /**
     * Drops the memo. `NumerosisServiceProvider` calls this on every boot; a
     * test that rebinds `globalCache` has to call it too.
     */
    public static function flush(): void
    {
        self::$store = null;
        self::$resolvedFor = null;
    }
}
