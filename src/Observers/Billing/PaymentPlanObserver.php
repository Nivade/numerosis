<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Observers\Billing;

use Nvade\Numerosis\Cache\CacheKeys;
use Nvade\Numerosis\Models\Central\PaymentPlan;
use Nvade\Numerosis\Observers\Concerns\ForgetsCacheKey;

/**
 * Keeps {@see \Nvade\Numerosis\Services\Billing\EloquentPaymentPlanRepository::available()}'s
 * cached list from outliving an admin edit: toggling `available` or changing
 * a price must be reflected immediately, never once the TTL happens to
 * expire.
 */
class PaymentPlanObserver
{
    use ForgetsCacheKey;

    public function saved(PaymentPlan $plan): void
    {
        $this->forgetCache(CacheKeys::availablePaymentPlans());
    }

    /**
     * Also forgets the popular slug: the cached value is a slug, so a deleted
     * plan leaves one that resolves to nothing.
     */
    public function deleted(PaymentPlan $plan): void
    {
        $this->forgetCache(CacheKeys::availablePaymentPlans());
        $this->forgetCache(CacheKeys::popularPaymentPlanSlug());
    }
}
