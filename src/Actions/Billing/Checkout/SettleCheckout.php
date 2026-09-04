<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing\Checkout;

use Laravel\Cashier\Subscription;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Contracts\Tenancy\ProvisionsTenant;
use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;
use Nvade\Numerosis\Data\Tenancy\TenantRegistrationData;
use Nvade\Numerosis\Enums\Tenancy\TenantProvisionStatus;
use Nvade\Numerosis\Events\Billing\CheckoutCompleted;
use Nvade\Numerosis\Models\Central\PendingTenantProvision;

/**
 * Records the subscription against the pending checkout and queues
 * provisioning.
 *
 * Provisioning is queued whether or not payment settled — only the recorded
 * status differs — because a trial collects nothing upfront and gating on
 * settlement would be stricter than the trial itself.
 */
class SettleCheckout
{
    use AsAction;

    public function __construct(private readonly ProvisionsTenant $provisioning) {}

    public function handle(
        PendingTenantProvision $pending,
        Subscription $subscription,
        ?string $stripeCustomerId,
        ?string $centralUserId,
    ): void {
        $settled = in_array($subscription->stripe_status, ['active', 'trialing'], true);
        $stripeSubscriptionId = $subscription->stripe_id;

        $pending->update([
            'stripe_subscription_id' => $stripeSubscriptionId,
            'status' => $settled ? TenantProvisionStatus::Provisioning : TenantProvisionStatus::AwaitingPayment,
        ]);

        $registration = new TenantRegistrationData(
            company_name: $pending->company_name,
            domain: $pending->domain,
            global_id: $pending->global_id,
            payment_plan: $pending->payment_plan,
            billing_cycle: $pending->billing_cycle,
            custom_domain: $pending->custom_domain,
        );

        $this->provisioning->queue(new TenantProvisionData(
            registration: $registration,
            stripeCustomerId: $stripeCustomerId,
            stripeSubscriptionId: $stripeSubscriptionId,
            centralUserId: $centralUserId,
        ));

        event(new CheckoutCompleted($pending->domain, (string) $pending->payment_plan, $stripeSubscriptionId));
    }
}
