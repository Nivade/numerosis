<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing\Checkout;

use Laravel\Cashier\Subscription;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Contracts\Tenancy\ProvisionsTenant;
use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;
use Nvade\Numerosis\Data\Tenancy\TenantRegistrationData;
use Nvade\Numerosis\Enums\TenantProvisionStatus;
use Nvade\Numerosis\Models\Central\PendingTenantProvision;

// See .claude/rules/billing-checkout.md.
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

        $pending->update([
            'stripe_subscription_id' => $subscription->stripe_id,
            'status' => $settled ? TenantProvisionStatus::Provisioning : TenantProvisionStatus::AwaitingPayment,
        ]);

        $registration = new TenantRegistrationData(
            company_name: $pending->company_name,
            domain: $pending->domain,
            global_id: $pending->global_id,
            payment_plan: $pending->payment_plan,
            billing_cycle: $pending->billing_cycle,
        );

        $this->provisioning->queue(new TenantProvisionData(
            registration: $registration,
            stripeCustomerId: $stripeCustomerId,
            stripeSubscriptionId: $subscription->stripe_id,
            centralUserId: $centralUserId,
        ));
    }
}
