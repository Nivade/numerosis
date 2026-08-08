<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Queries;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Models\User;
use Nvade\Numerosis\Services\Tenancy\UserModelResolver;
use Nvade\Numerosis\Support\Cache\CacheKeys;

/**
 * @method static ?User run(string $globalId, ?Context $context = null)
 */
class FindUserByGlobalId
{
    use AsAction;

    /**
     * Only the raw attributes are cached, never the model instance: both user
     * models carry $with-eager-loaded or lazily-loaded relations that would
     * otherwise be baked into the cache at write time and go stale the moment
     * something outside this action's own saved/deleted hooks changes them
     * (e.g. a Membership row). Rehydrating via newFromBuilder() gives back a
     * model with no relations loaded, exactly like a fresh query would.
     */
    public function handle(string $globalId, ?Context $context = null): ?User
    {
        $context ??= tenancy()->initialized ? Context::Tenant : Context::Central;
        $model = $context === Context::Tenant
            ? UserModelResolver::tenantUserModel()
            : UserModelResolver::centralUserModel();

        $attributes = global_cache()->remember(
            CacheKeys::userModel($globalId, $context),
            now()->addHour(),
            fn () => $model::query()->where('global_id', $globalId)->first()?->getAttributes()
        );

        if ($attributes === null) {
            return null;
        }

        return (new $model)->newFromBuilder($attributes);
    }
}
