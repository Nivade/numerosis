<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Modules;

use Nvade\Numerosis\Contracts\Billing\ModuleOffer;
use Nvade\Numerosis\Enums\BillingCycle;
use Nvade\Numerosis\Models\Tenant\Module;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * @method static Module run(ModuleOffer $offer, ?string $subscriptionItemId, ?BillingCycle $cycle)
 */
class RecordModulePurchase
{
    use AsAction;

    public function handle(ModuleOffer $offer, ?string $subscriptionItemId, ?BillingCycle $cycle): Module
    {
        return Module::updateOrCreate(
            ['name' => $offer->slug()],
            [
                'description' => $offer->description(),
                'enabled' => true,
                'purchased_at' => now(),
                'stripe_subscription_item_id' => $subscriptionItemId,
                'billing_cycle' => $cycle,
            ],
        );
    }
}
