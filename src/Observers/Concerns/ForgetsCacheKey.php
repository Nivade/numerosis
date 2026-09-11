<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Observers\Concerns;

use Nvade\Numerosis\Cache\GlobalCache;

trait ForgetsCacheKey
{
    protected function forgetCache(string $key): void
    {
        GlobalCache::store()->forget($key);
    }
}
