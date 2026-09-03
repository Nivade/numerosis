<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Observers;

use Nvade\Numerosis\Models\Central\PaymentPlan;
use Nvade\Numerosis\Observers\Concerns\ForgetsCacheKey;
use Nvade\Numerosis\Support\Cache\CacheKeys;

/**
 * Keeps {@see \Nvade\Numerosis\Services\Billing\Plans\EloquentPaymentPlanRepository::available()}'s
 * cached list from outliving an admin edit — an admin toggling
 * `available` or changing a price must be reflected immediately, not
 * after the TTL happens to expire.
 */
class PaymentPlanObserver
{
    use ForgetsCacheKey;

    public function saved(PaymentPlan $plan): void
    {
        $this->forgetCache(CacheKeys::availablePaymentPlans());
    }

    public function deleted(PaymentPlan $plan): void
    {
        $this->forgetCache(CacheKeys::availablePaymentPlans());
    }
}
