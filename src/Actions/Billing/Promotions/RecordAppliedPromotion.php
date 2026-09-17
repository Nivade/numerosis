<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing\Promotions;

use Illuminate\Database\UniqueConstraintViolationException;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Data\Billing\PromotionData;
use Nvade\Numerosis\Models\Central\AppliedPromotion;
use Nvade\Numerosis\Numerosis;

/**
 * Writes the audit row for a redemption. Keyed on subscription and promotion
 * code, so the webhook and the checkout request recording the same application
 * leave one row rather than two.
 *
 * @method static AppliedPromotion|null run(PromotionData $promotion, string $stripeSubscriptionId, ?string $globalId = null, ?string $tenantId = null)
 */
class RecordAppliedPromotion
{
    use AsAction;

    public function handle(
        PromotionData $promotion,
        string $stripeSubscriptionId,
        ?string $globalId = null,
        ?string $tenantId = null,
    ): ?AppliedPromotion {
        $promotionClass = Numerosis::model(AppliedPromotion::class);

        try {
            /** @var AppliedPromotion $row */
            $row = $promotionClass::query()->updateOrCreate(
                [
                    'stripe_subscription_id' => $stripeSubscriptionId,
                    'stripe_promotion_code_id' => $promotion->promotion_code_id,
                ],
                [
                    'tenant_id' => $tenantId,
                    'global_id' => $globalId,
                    'code' => $promotion->code,
                    'stripe_coupon_id' => $promotion->coupon_id,
                    'percent_off' => $promotion->percent_off,
                    'amount_off' => $promotion->amount_off,
                    'currency' => $promotion->currency,
                    'applied_at' => now(),
                ],
            );

            return $row;
        } catch (UniqueConstraintViolationException) {
            // The other writer won the race between the select and the insert;
            // its row says the same thing this one would have.
            return $promotionClass::query()
                ->where('stripe_subscription_id', $stripeSubscriptionId)
                ->where('stripe_promotion_code_id', $promotion->promotion_code_id)
                ->first();
        }
    }
}
