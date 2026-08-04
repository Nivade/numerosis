<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Queries;

use Nvade\Numerosis\Contracts\Auth\CentralUserModel;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Support\Cache\CacheKeys;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

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
        return global_cache()->remember(
            CacheKeys::userTenants($globalId),
            now()->addHour(),
            function () use ($globalId) {
                $user = FindUserByGlobalId::run($globalId, Context::Central);

                return $user instanceof CentralUserModel ? $user->tenants()->get() : new Collection;
            }
        );
    }
}
