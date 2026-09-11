<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Queries;

use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Cache\CacheKeys;
use Nvade\Numerosis\Cache\GlobalCache;
use Nvade\Numerosis\Contracts\Auth\CentralUserModel;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Models\Central\Tenant;

/**
 * @method static Collection<int, Tenant> run(string $globalId)
 */
class GetTenantsByGlobalId
{
    use AsAction;

    /**
     * Per-request memo, on top of the hour-long global cache.
     *
     * `Authenticate` reaches this through `User::canAccessTenant()` on every
     * authenticated tenant request, and each miss deserializes every one of
     * the user's tenant models to answer a `contains`. A request-lifetime memo
     * adds no staleness the hour TTL does not already carry.
     *
     * @var array<string, Collection<int, Tenant>>
     */
    private static array $memo = [];

    /**
     * @return Collection<int, Tenant>
     */
    public function handle(string $globalId): Collection
    {
        if (isset(self::$memo[$globalId])) {
            return self::$memo[$globalId];
        }

        /**
         * `tenants()->get()` is typed against the relation's own declaration
         * (`Model`), and the cache round-trip widens it further, so the shape
         * is asserted once here, sparing every caller.
         *
         * @var Collection<int, Tenant> $tenants
         */
        $tenants = GlobalCache::store()->remember(
            CacheKeys::userTenants($globalId),
            now()->addHour(),
            function () use ($globalId) {
                $user = FindUserByGlobalId::run($globalId, Context::Central);

                return $user instanceof CentralUserModel ? $user->tenants()->get() : new Collection;
            }
        );

        return self::$memo[$globalId] = $tenants;
    }

    /**
     * Drops the memo. Anything invalidating `CacheKeys::userTenants()` within
     * the same request has to call this too, or it reads its own stale answer
     * back.
     */
    public static function flushMemo(): void
    {
        self::$memo = [];
    }
}
