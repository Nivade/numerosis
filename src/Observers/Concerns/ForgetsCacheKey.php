<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Observers\Concerns;

trait ForgetsCacheKey
{
    protected function forgetCache(string $key): void
    {
        global_cache()->forget($key);
    }
}
