<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing\Promotions;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Actions\Queries\GetActiveSubscription;
use Nvade\Numerosis\Data\Billing\PromotionData;
use Nvade\Numerosis\Exceptions\Billing\PromotionCodeUnavailable;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Models\Central\Tenant;
use Stripe\Exception\ApiErrorException;

/**
 * Applies the retention code to the subscription the owner was about to close.
 * Re-resolved rather than taken from the request: the only code this can apply
 * is the one config names, however the button was pressed.
 *
 * @method static PromotionData|null run(Tenant $tenant)
 */
class AcceptRetentionOffer
{
    use AsAction;

    public function handle(Tenant $tenant): ?PromotionData
    {
        $offer = ResolveRetentionOffer::run($tenant);
        $subscription = GetActiveSubscription::run($tenant);

        if (! $offer instanceof PromotionData || ! $subscription instanceof Subscription) {
            return null;
        }

        try {
            $subscription->applyPromotionCode($offer->promotion_code_id);
        } catch (ApiErrorException|PromotionCodeUnavailable) {
            return null;
        }

        RecordAppliedPromotion::run($offer, $subscription->stripe_id, null, (string) $tenant->getTenantKey());

        return $offer;
    }
}
