<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing\Promotions;

use Laravel\Cashier\Cashier;
use Laravel\Cashier\PromotionCode;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Contracts\Billing\BillableUser;
use Nvade\Numerosis\Contracts\Billing\Plan;
use Nvade\Numerosis\Data\Billing\PromotionData;
use Nvade\Numerosis\Enums\Billing\BillingCycle;
use Nvade\Numerosis\Exceptions\Billing\PromotionCodeUnavailable;
use Nvade\Numerosis\Models\Central\Tenant;
use Stripe\Coupon as StripeCoupon;
use Stripe\Exception\ApiErrorException;
use Stripe\PromotionCode as StripePromotionCode;

/**
 * Nothing here is cached. A redemption limit moves between the page load and
 * the charge, so the answer is asked again when the subscription is created.
 *
 * @method static PromotionData run(string $code, BillableUser|Tenant $billable, ?Plan $plan = null, ?BillingCycle $cycle = null)
 *
 * @throws PromotionCodeUnavailable
 */
class ValidatePromotionCode
{
    use AsAction;

    public function handle(string $code, BillableUser|Tenant $billable, ?Plan $plan = null, ?BillingCycle $cycle = null): PromotionData
    {
        $promotionCode = $this->find($billable, trim($code));
        $coupon = $this->coupon($promotionCode);

        $this->assertLive($promotionCode, $coupon);
        $this->assertBelongsTo($promotionCode, $billable);
        $this->assertRestrictionsAllow($promotionCode, $billable, $plan, $cycle);
        $this->assertCouponAppliesTo($coupon, $plan, $cycle);

        return PromotionData::fromStripe($coupon, $promotionCode, $promotionCode->expires_at);
    }

    /**
     * Deliberately not `findActivePromotionCode()`: that filters inactive codes
     * out server-side, which turns "expired" into "unknown" and sends the
     * customer back to check a spelling that was right.
     */
    private function find(BillableUser|Tenant $billable, string $code): StripePromotionCode
    {
        if ($code === '') {
            throw PromotionCodeUnavailable::unknown($code);
        }

        try {
            $promotionCode = $billable->findPromotionCode($code, ['expand' => ['data.coupon']]);
        } catch (ApiErrorException) {
            throw PromotionCodeUnavailable::unreadable();
        }

        if (! $promotionCode instanceof PromotionCode) {
            throw PromotionCodeUnavailable::unknown($code);
        }

        return $promotionCode->asStripePromotionCode();
    }

    private function coupon(StripePromotionCode $promotionCode): StripeCoupon
    {
        $coupon = $promotionCode->promotion->coupon ?? null;

        if ($coupon instanceof StripeCoupon) {
            return $coupon;
        }

        if (! is_string($coupon)) {
            throw PromotionCodeUnavailable::unreadable();
        }

        try {
            return Cashier::stripe()->coupons->retrieve($coupon);
        } catch (ApiErrorException) {
            throw PromotionCodeUnavailable::unreadable();
        }
    }

    private function assertLive(StripePromotionCode $promotionCode, StripeCoupon $coupon): void
    {
        if (! $promotionCode->active || ! $coupon->valid) {
            throw PromotionCodeUnavailable::expired();
        }

        $now = now()->getTimestamp();

        if ($promotionCode->expires_at !== null && $promotionCode->expires_at <= $now) {
            throw PromotionCodeUnavailable::expired();
        }

        if ($coupon->redeem_by !== null && $coupon->redeem_by <= $now) {
            throw PromotionCodeUnavailable::expired();
        }

        if ($promotionCode->max_redemptions !== null && $promotionCode->times_redeemed >= $promotionCode->max_redemptions) {
            throw PromotionCodeUnavailable::exhausted();
        }
    }

    /** A code pinned to one customer is not a code anybody else can type. */
    private function assertBelongsTo(StripePromotionCode $promotionCode, BillableUser|Tenant $billable): void
    {
        $customer = $promotionCode->customer;

        if ($customer === null) {
            return;
        }

        $customerId = is_string($customer) ? $customer : $customer->id;

        if ($customerId !== $billable->stripeId()) {
            throw PromotionCodeUnavailable::otherCustomer();
        }
    }

    private function assertRestrictionsAllow(
        StripePromotionCode $promotionCode,
        BillableUser|Tenant $billable,
        ?Plan $plan,
        ?BillingCycle $cycle,
    ): void {
        $restrictions = $promotionCode->restrictions;

        if ($restrictions->first_time_transaction && $billable->subscriptions()->exists()) {
            throw PromotionCodeUnavailable::firstPurchaseOnly();
        }

        $minimum = $restrictions->minimum_amount;
        $price = $plan instanceof Plan && $cycle instanceof BillingCycle ? $plan->price($cycle) : null;

        if ($minimum !== null && $price !== null && $price < $minimum) {
            throw PromotionCodeUnavailable::minimumAmount(
                Cashier::formatAmount($minimum, $restrictions->minimum_amount_currency),
            );
        }
    }

    /**
     * A coupon restricted to products only applies if the plan's price sits on
     * one of them, which costs one extra Stripe read and only when restricted.
     */
    private function assertCouponAppliesTo(StripeCoupon $coupon, ?Plan $plan, ?BillingCycle $cycle): void
    {
        $products = $coupon->applies_to->products ?? null;

        if (! is_array($products) || $products === []) {
            return;
        }

        $priceId = $plan instanceof Plan && $cycle instanceof BillingCycle ? $plan->priceId($cycle) : null;

        if ($priceId === null || $priceId === '') {
            throw PromotionCodeUnavailable::productRestricted();
        }

        try {
            $product = Cashier::stripe()->prices->retrieve($priceId)->product;
        } catch (ApiErrorException) {
            throw PromotionCodeUnavailable::unreadable();
        }

        $productId = is_string($product) ? $product : $product?->id;

        if (! is_string($productId) || ! in_array($productId, $products, true)) {
            throw PromotionCodeUnavailable::productRestricted();
        }
    }
}
