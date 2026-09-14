<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Cache;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Cache\CacheKeys;
use Nvade\Numerosis\Cache\GlobalCache;
use Nvade\Numerosis\Services\Billing\EloquentPaymentPlanRepository;

class ForgetAvailablePaymentPlans
{
    use AsAction;

    public function handle(): void
    {
        GlobalCache::store()->forget(CacheKeys::availablePaymentPlans());

        // The reader memoizes for the rest of the request, so forgetting the
        // cache entry alone would leave it answering from before this call.
        EloquentPaymentPlanRepository::flushMemo();
    }
}
