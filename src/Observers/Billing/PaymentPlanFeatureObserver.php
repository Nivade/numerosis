<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Observers\Billing;

use Nvade\Numerosis\Cache\CacheKeys;
use Nvade\Numerosis\Models\Central\PaymentPlanFeature;
use Nvade\Numerosis\Observers\Concerns\ForgetsCacheKey;

/**
 * Features are rendered alongside their plan, so an edit here must bust
 * the cached plan payload too. {@see PaymentPlanObserver}
 */
class PaymentPlanFeatureObserver
{
    use ForgetsCacheKey;

    public function saved(PaymentPlanFeature $feature): void
    {
        $this->forgetCache(CacheKeys::availablePaymentPlans());
    }

    public function deleted(PaymentPlanFeature $feature): void
    {
        $this->forgetCache(CacheKeys::availablePaymentPlans());
    }
}
