<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Observers\Billing;

use Nvade\Numerosis\Cache\CacheKeys;
use Nvade\Numerosis\Contracts\Billing\Entitlements;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Observers\Concerns\ForgetsCacheKey;
use Nvade\Numerosis\Services\Billing\PlanEntitlements;

/**
 * Keeps {@see \Nvade\Numerosis\Services\Billing\EloquentPaymentPlanRepository::mostPopularSlug()}'s
 * cached slug in step with subscription churn, which is what the aggregate
 * counts. `saved` rather than `created`, because a row moving to a different
 * `payment_plan_id` changes the aggregate too.
 */
class SubscriptionObserver
{
    use ForgetsCacheKey;

    public function saved(Subscription $subscription): void
    {
        $this->forgetCache(CacheKeys::popularPaymentPlanSlug());

        $entitlements = resolve(Entitlements::class);
        $owner = $subscription->subscribable;

        if ($entitlements instanceof PlanEntitlements && $owner instanceof Tenant) {
            $entitlements->forget($owner);
        }
    }

    public function deleted(Subscription $subscription): void
    {
        $this->forgetCache(CacheKeys::popularPaymentPlanSlug());
    }
}
