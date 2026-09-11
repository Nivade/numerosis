<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Queries;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Boot\UserModels;
use Nvade\Numerosis\Cache\CacheKeys;
use Nvade\Numerosis\Cache\GlobalCache;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Models\User;

/**
 * @method static ?User run(string $globalId, ?Context $context = null)
 */
class FindUserByGlobalId
{
    use AsAction;

    /**
     * Only the raw attributes are cached, never the model instance: both user
     * models carry relations that would be baked into the cache at write time
     * and go stale as soon as something outside this action's own saved and
     * deleted hooks changed them. Rehydrating via newFromBuilder() gives back a
     * model with no relations loaded, exactly like a fresh query would.
     */
    public function handle(string $globalId, ?Context $context = null): ?User
    {
        $context ??= tenancy()->initialized ? Context::Tenant : Context::Central;
        $model = UserModels::for($context);

        $attributes = GlobalCache::store()->remember(
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
