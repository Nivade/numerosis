<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Support\Cache;

use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Repository;
use RuntimeException;

/**
 * Typed accessor for stancl's `globalCache` binding — the one every key in
 * {@see CacheKeys} documented as *global* is read and invalidated through.
 *
 * The binding is not tenant-scoped: `Stancl\Tenancy\TenancyServiceProvider`
 * points it at a plain `Illuminate\Cache\CacheManager`, with no tenant tag and
 * no tenant prefix. That is the whole point of it (central data has to survive
 * `CacheTenancyBootstrapper`'s prefixing) and also its hazard — anything
 * derived from a tenant database must carry the tenant in its key.
 *
 * Why this exists rather than calling stancl's `global_cache()` helper
 * directly at each site: **dev-master declares that helper `: mixed`** (v3
 * did not), so every `global_cache()->remember(...)` in this package became a
 * `Cannot call method remember() on mixed` at PHPStan level 9 on that leg —
 * ten call sites, no defect at any of them. Narrowing once, here, is both the
 * smaller change and the honest one: the binding really is a cache repository,
 * and if a host ever rebinds it to something else this fails loudly at the
 * point of the mistake instead of at a `->remember()` call somewhere else.
 *
 * The binding is `bind`, not `singleton`, so each call really does construct a
 * fresh manager — which is why `CACHE_STORE=array` makes it inert in tests and
 * why `tests/Concerns/PinsGlobalCache` exists.
 */
final class GlobalCache
{
    private function __construct() {}

    /**
     * Note the `Factory` branch is the one that actually runs: stancl binds
     * `globalCache` to an `Illuminate\Cache\CacheManager`, which implements
     * `Factory` and *not* `Repository` — every `global_cache()->remember(...)`
     * call in this package's history reached the default store through
     * `CacheManager::__call()`, which is exactly what `->store()` returns.
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
