<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Laravel\Cashier\Cashier;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Actions\Billing\Subscriptions\LinkSubscriptionToTenant;
use Nvade\Numerosis\Contracts\Tenancy\ConsumesContributions;
use Nvade\Numerosis\Data\Billing\StripeSubscriptionData;
use Nvade\Numerosis\Data\Tenancy\BillingContribution;
use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Central\TenantProvision;
use Nvade\Numerosis\Numerosis;
use Override;

/**
 * Attaches the Stripe subscription paid for at checkout to the tenant it
 * created.
 *
 * Declaring what it consumes is what makes it skippable: a tenant provisioned
 * without billing has no BillingContribution, and the runner records the skip
 * rather than this step re-checking a fact the chain builder also knew.
 */
class LinkTenantSubscription implements ConsumesContributions
{
    use AsAction;

    /**
     * @return list<class-string<\Nvade\Numerosis\Contracts\Tenancy\ProvisionContribution>>
     */
    #[Override]
    public static function consumes(): array
    {
        return [BillingContribution::class];
    }

    public function handle(TenantProvision $provision): void
    {
        $billing = $provision->contribution(BillingContribution::class);

        if ($billing === null) {
            return;
        }

        if ($billing->stripe_customer_id === null || $billing->stripe_customer_id === ''
            || $billing->stripe_subscription_id === null || $billing->stripe_subscription_id === '') {
            return;
        }

        $tenant = Numerosis::model(Tenant::class)::findOrFail($provision->slug);

        $stripeSubscription = Cashier::stripe()->subscriptions->retrieve($billing->stripe_subscription_id);

        LinkSubscriptionToTenant::run(
            TenantProvisionData::fromProvision($provision),
            StripeSubscriptionData::fromStripe($stripeSubscription),
            $tenant,
        );
    }
}
