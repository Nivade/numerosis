<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Cache;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Support\Cache\CacheKeys;
use Nvade\Numerosis\Support\Cache\GlobalCache;

class ForgetUserTenants
{
    use AsAction;

    public function handle(string $globalId): void
    {
        GlobalCache::store()->forget(CacheKeys::userTenants($globalId));
    }
}
