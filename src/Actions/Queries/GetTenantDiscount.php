<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Queries;

use Illuminate\Support\Facades\Date;
use Laravel\Cashier\Discount;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Data\Billing\PromotionData;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Models\Central\Tenant;
use Stripe\Exception\ApiErrorException;

/**
 * The discount Stripe currently reports on the tenant's subscription, read
 * from Stripe rather than from `applied_promotions`: the local row records that
 * a code was redeemed, and says nothing about whether it has since ended.
 *
 * Null when there is none, and also null when Stripe could not be reached — a
 * screen that renders a discount during an outage is worse than one that omits
 * it.
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

        $end = $discount->asStripeDiscount()->end;

        return new PromotionData(
            code: $promotionCode->code ?? $coupon->id,
            promotion_code_id: $promotionCode->id ?? '',
            coupon_id: $coupon->id,
            percent_off: $coupon->percent_off === null ? null : (int) $coupon->percent_off,
            amount_off: $coupon->amount_off,
            currency: $coupon->currency,
            duration: $coupon->duration,
            duration_in_months: $coupon->duration_in_months,
            expires_at: $end === null ? null : Date::createFromTimestamp($end),
        );
    }
}
