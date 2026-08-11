<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Illuminate\Contracts\Queue\ShouldQueue;
use Laravel\Cashier\Cashier;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Actions\Billing\Subscriptions\LinkSubscriptionToTenant;
use Nvade\Numerosis\Data\Billing\StripeSubscriptionData;
use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;
use Nvade\Numerosis\Models\Central\Tenant;

/**
 * Attaches the Stripe subscription paid for at checkout to the tenant it
 * created. Runs as a provisioning step, and does nothing when the tenant was
 * created without a subscription.
 */
class LinkTenantSubscription implements ShouldQueue
{
    use AsAction;

    public function handle(Tenant $tenant, TenantProvisionData $data): void
    {
        if (! $data->stripeCustomerId || ! $data->stripeSubscriptionId) {
            return;
        }

        $stripeSubscription = Cashier::stripe()->subscriptions->retrieve($data->stripeSubscriptionId);

        LinkSubscriptionToTenant::run(
            $data,
            StripeSubscriptionData::fromStripe($stripeSubscription),
            $tenant,
        );
    }
}
