<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Queries;

use Laravel\Cashier\Discount;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Data\Billing\PromotionData;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Models\Central\Tenant;
use Stripe\Exception\ApiErrorException;

/**
 * Null when there is no discount, and null again when Stripe could not be
 * reached. A screen that renders a stale discount during an outage is worse
 * than one that omits it.
 *
 * @method static PromotionData|null run(Tenant $tenant)
 */
class GetTenantDiscount
{
    use AsAction;

    public function handle(Tenant $tenant): ?PromotionData
    {
        $subscription = GetActiveSubscription::run($tenant);

        if (! $subscription instanceof Subscription) {
            return null;
        }

        try {
            $discount = $subscription->discount();
        } catch (ApiErrorException) {
            return null;
        }

        if (! $discount instanceof Discount) {
            return null;
        }

        try {
            $coupon = $discount->coupon()->asStripeCoupon();
            $promotionCode = $discount->promotionCode()?->asStripePromotionCode();
        } catch (ApiErrorException) {
            return null;
        }

        return PromotionData::fromStripe($coupon, $promotionCode, $discount->asStripeDiscount()->end);
    }
}
