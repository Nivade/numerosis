<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Cache;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Actions\Queries\GetTenantsByGlobalId;
use Nvade\Numerosis\Support\Cache\CacheKeys;
use Nvade\Numerosis\Support\Cache\GlobalCache;

class ForgetUserTenants
{
    use AsAction;

    public function handle(string $globalId): void
    {
        GlobalCache::store()->forget(CacheKeys::userTenants($globalId));

        // The reader memoizes for the rest of the request, so forgetting the
        // cache entry alone would leave it answering from before this call.
        GetTenantsByGlobalId::flushMemo();
    }
}
