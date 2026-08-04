<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Observers;

use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Models\Tenant\User;
use Nvade\Numerosis\Observers\Concerns\ForgetsCacheKey;
use Nvade\Numerosis\Support\Cache\CacheKeys;

/**
 * Keeps {@see \Nvade\Numerosis\Actions\Queries\FindUserByGlobalId}'s cached entry from
 * outliving the row it describes — a stale `is_bot` or renamed user would
 * otherwise be authenticated by {@see \Nvade\Numerosis\Actions\Auth\LoginUser}. Runs
 * inside tenant context, so {@see CacheKeys::userModel()} resolves the
 * same tenant-scoped key the read used.
 */
class TenantUserObserver
{
    use ForgetsCacheKey;

    public function saved(User $user): void
    {
        $this->forgetCache(CacheKeys::userModel($user->global_id, Context::Tenant));
    }

    public function deleted(User $user): void
    {
        $this->forgetCache(CacheKeys::userModel($user->global_id, Context::Tenant));
    }
}
