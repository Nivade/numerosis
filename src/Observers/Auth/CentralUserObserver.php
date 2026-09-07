<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Observers\Auth;

use Nvade\Numerosis\Actions\Auth\PromoteFirstCentralUserToAdmin;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Observers\Concerns\ForgetsCacheKey;
use Nvade\Numerosis\Support\Cache\CacheKeys;

/**
 * Keeps {@see \Nvade\Numerosis\Actions\Queries\FindUserByGlobalId}'s cached entry from
 * outliving the row it describes: a stale `is_bot` or renamed user would
 * otherwise be authenticated by {@see \Nvade\Numerosis\Actions\Auth\LoginUser}.
 */
class CentralUserObserver
{
    use ForgetsCacheKey;

    public function saved(CentralUser $user): void
    {
        $this->forgetCache(CacheKeys::userModel($user->global_id, Context::Central));
    }

    public function deleted(CentralUser $user): void
    {
        $this->forgetCache(CacheKeys::userModel($user->global_id, Context::Central));
    }

    public function created(CentralUser $user): void
    {
        PromoteFirstCentralUserToAdmin::run($user);
    }
}
