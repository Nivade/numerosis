<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing\Promotions;

use Illuminate\Support\Facades\Config;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Actions\Queries\GetActiveSubscription;
use Nvade\Numerosis\Actions\Queries\GetTenantDiscount;
use Nvade\Numerosis\Data\Billing\PromotionData;
use Nvade\Numerosis\Exceptions\Billing\PromotionCodeUnavailable;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Models\Central\Tenant;

/**
 * The code offered to an owner about to close their workspace, or null when
 * there is nothing to offer. Validated against Stripe before it is shown: a
 * host whose retention coupon was retired gets the plain close flow rather than
 * a button that fails when pressed.
 *
 * @method static PromotionData|null run(Tenant $tenant)
 */
class ResolveRetentionOffer
{
    use AsAction;

    public function handle(Tenant $tenant): ?PromotionData
    {
        $code = Config::get('numerosis.billing.promotions.retention_code');

        if (! is_string($code) || trim($code) === '') {
            return null;
        }

        // Nothing to discount without a live subscription, and a tenant already
        // holding a discount is not offered a second one.
        $subscription = GetActiveSubscription::run($tenant);

        if (! $subscription instanceof Subscription || GetTenantDiscount::run($tenant) instanceof PromotionData) {
            return null;
        }

        try {
            return ValidatePromotionCode::run($code, $tenant);
        } catch (PromotionCodeUnavailable) {
            return null;
        }
    }
}
