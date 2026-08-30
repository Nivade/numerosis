<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Queries;

use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Contracts\Auth\CentralUserModel;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Support\Cache\CacheKeys;
use Nvade\Numerosis\Support\Cache\GlobalCache;

/**
 * @method static Collection<int, Tenant> run(string $globalId)
 */
class GetTenantsByGlobalId
{
    use AsAction;

    /**
     * @return Collection<int, Tenant>
     */
    public function handle(string $globalId): Collection
    {
        /**
         * `tenants()->get()` is typed against the relation's own declaration
         * (`Model`), and the cache round-trip widens it further, so the shape
         * is asserted once here rather than at every caller.
         *
         * @var Collection<int, Tenant>
         */
        return GlobalCache::store()->remember(
            CacheKeys::userTenants($globalId),
            now()->addHour(),
            function () use ($globalId) {
                $user = FindUserByGlobalId::run($globalId, Context::Central);

                return $user instanceof CentralUserModel ? $user->tenants()->get() : new Collection;
            }
        );
    }
}
