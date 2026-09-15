<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Queries;

use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Cache\CacheKeys;
use Nvade\Numerosis\Cache\CacheTtl;
use Nvade\Numerosis\Cache\GlobalCache;
use Nvade\Numerosis\Contracts\Auth\CentralUserModel;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Numerosis;
use UnexpectedValueException;

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

        if ($ids === []) {
            return new Collection;
        }

        $models = Numerosis::model(Tenant::class)::query()->whereIn('id', $ids)->get();

        $byId = [];

        foreach ($models as $model) {
            throw_unless($model instanceof Tenant, new UnexpectedValueException('The configured tenant model does not extend '.Tenant::class.'.'));

            $key = $model->getKey();

            if (is_string($key)) {
                $byId[$key] = $model;
            }
        }

        // The cached id list drives the order, so two reads of one list agree.
        return new Collection(array_values(array_filter(array_map(
            fn (string $id): ?Tenant => $byId[$id] ?? null,
            $ids,
        ))));
    }
}
