<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Cache;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Cache\CacheKeys;
use Nvade\Numerosis\Cache\GlobalCache;

class ForgetAvailablePaymentPlans
{
    use AsAction;

    public function handle(): void
    {
        GlobalCache::store()->forget(CacheKeys::availablePaymentPlans());
    }
}
