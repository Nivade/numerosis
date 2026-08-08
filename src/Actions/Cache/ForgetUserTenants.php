<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Cache;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Support\Cache\CacheKeys;

class ForgetUserTenants
{
    use AsAction;

    public function handle(string $globalId): void
    {
        global_cache()->forget(CacheKeys::userTenants($globalId));
    }
}
