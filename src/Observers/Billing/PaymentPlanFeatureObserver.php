<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Observers\Billing;

use Nvade\Numerosis\Actions\Cache\ForgetAvailablePaymentPlans;
use Nvade\Numerosis\Models\Central\PaymentPlanFeature;

/**
 * Features are rendered alongside their plan, so an edit here must bust
 * the cached plan payload too. {@see PaymentPlanObserver}
 */
class PaymentPlanFeatureObserver
{
    public function saved(PaymentPlanFeature $feature): void
    {
        ForgetAvailablePaymentPlans::run();
    }

    public function deleted(PaymentPlanFeature $feature): void
    {
        ForgetAvailablePaymentPlans::run();
    }
}
