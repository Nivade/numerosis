<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Cache;

use Closure;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Config;
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
     * Memoized because `globalCache` is a `bind`: every resolve built a fresh
     * `CacheManager`, and a five-tenant list page built roughly sixteen of
     * them. Keyed on the container so an Octane worker never reads a store
     * built for a different application, and on the resolved store name so a
     * changed `numerosis.cache.store` or `cache.default` takes effect.
     */
    private static ?Repository $store = null;

    private static ?Container $resolvedFor = null;

    private static ?string $resolvedName = null;

    private function __construct() {}

    /**
     * The `Factory` branch is the one that runs: stancl binds `globalCache` to
     * an `Illuminate\Cache\CacheManager`, which implements `Factory` and never
     * `Repository`. A binding resolving straight to a `Repository` cannot
     * honour `numerosis.cache.store`.
     */
    public static function store(): Repository
    {
        $container = app();
        $name = self::storeName();

        if (self::$store instanceof Repository && self::$resolvedFor === $container && self::$resolvedName === $name) {
            return self::$store;
        }

        $cache = $container->make('globalCache');

        $store = match (true) {
            $cache instanceof Repository => $cache,
            $cache instanceof Factory => $cache->store($name),
            default => throw new RuntimeException(
                'The [globalCache] container binding must resolve to a cache '
                .'repository or factory; got '.get_debug_type($cache).'.'
            ),
        };

        self::$resolvedFor = $container;
        self::$resolvedName = $name;

        return self::$store = $store;
    }

    /**
     * Falls back to `cache.default` rather than null: the memo compares this,
     * and a null would hide a changed default behind a store built for the old
     * one.
     */
    private static function storeName(): ?string
    {
        $name = Config::get('numerosis.cache.store');

        if (is_string($name)) {
            return $name;
        }

        $default = Config::get('cache.default');

        return is_string($default) ? $default : null;
    }

    /**
     * A null `$ttl` bypasses the store completely. A zero-second entry would
     * not cache either: `Repository::put()` turns any `$seconds <= 0` into a
     * `forget()` before a driver sees it.
     *
     * @template TValue
     *
     * @param  Closure(): TValue  $callback
     * @return TValue
     */
    public static function remember(string $key, ?int $ttl, Closure $callback): mixed
    {
        if ($ttl === null) {
            return $callback();
        }

        return self::store()->remember($key, $ttl, $callback);
    }

    /**
     * Stale-while-revalidate, for keys whose invalidator busts them often
     * enough that the recompute stampedes. Falls back to
     * {@see self::remember()} on a store that cannot take the lock the
     * background refresh needs.
     *
     * @template TValue
     *
     * @param  array{int, int}|null  $window  `[fresh, stale]` seconds; null does not cache
     * @param  Closure(): TValue  $callback
     * @return TValue
     */
    public static function flexible(string $key, ?array $window, Closure $callback): mixed
    {
        if ($window === null) {
            return $callback();
        }

        $store = self::store();

        if (! $store instanceof CacheRepository || ! $store->getStore() instanceof LockProvider) {
            return $store->remember($key, $window[0], $callback);
        }

        return $store->flexible($key, $window, $callback);
    }

    /**
     * Locks taken through the `Cache` facade inside tenant context are
     * tenant-prefixed, which silently splits a lock meant to serialize a
     * central mutation against a tenant-context caller.
     */
    public static function lock(string $name, int $seconds): Lock
    {
        $store = self::store()->getStore();

        throw_unless($store instanceof LockProvider, RuntimeException::class,
            'The global cache store ['.get_debug_type($store).'] does not support locks.');

        return $store->lock($name, $seconds);
    }

    /**
     * Drops the memo. `NumerosisServiceProvider` calls this on every boot; a
     * test that rebinds `globalCache` has to call it too.
     */
    public static function flush(): void
    {
        self::$store = null;
        self::$resolvedFor = null;
        self::$resolvedName = null;
    }
}
