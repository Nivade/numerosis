<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Cache;

use Nvade\Numerosis\Support\Cache\CacheKeys;
use Lorisleiva\Actions\Concerns\AsAction;

class ForgetUserTenants
{
    use AsAction;

    public function handle(string $globalId): void
    {
        global_cache()->forget(CacheKeys::userTenants($globalId));
    }
}
