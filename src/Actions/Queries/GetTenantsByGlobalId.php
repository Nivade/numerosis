<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Queries;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Cache\CacheKeys;
use Nvade\Numerosis\Cache\CacheTtl;
use Nvade\Numerosis\Cache\GlobalCache;
use Nvade\Numerosis\Contracts\Auth\CentralUserModel;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Numerosis;

/**
 * @method static Collection<int, Tenant> run(string $globalId)
 */
class GetTenantsByGlobalId
{
    use AsAction;

    /**
     * Per-request memo over a global cache that holds tenant ids, not models.
     * `Authenticate` reaches this through `User::canAccessTenant()` on every
     * authenticated tenant request, and every call without the memo re-queries
     * the id list back into models.
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

        /** @var list<string> $ids */
        $ids = GlobalCache::remember(
            CacheKeys::userTenants($globalId),
            CacheTtl::userTenants(),
            function () use ($globalId): array {
                $user = FindUserByGlobalId::run($globalId, Context::Central);

                if (! $user instanceof CentralUserModel) {
                    return [];
                }

                /** @var list<string> $ids */
                $ids = $user->tenants()->pluck('tenants.id')->all();

                return $ids;
            }
        );

        // The query is typed against `Model`, so the shape is narrowed once
        // here, sparing every caller.
        $models = $ids === []
            ? []
            : Numerosis::model(Tenant::class)::query()->whereIn('id', $ids)->get()->all();

        $tenants = array_values(array_filter($models, fn (Model $model): bool => $model instanceof Tenant));

        return self::$memo[$globalId] = new Collection($tenants);
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
