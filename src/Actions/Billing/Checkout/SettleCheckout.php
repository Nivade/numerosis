<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing\Checkout;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Contracts\Tenancy\ProvisionsTenant;
use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;
use Nvade\Numerosis\Data\Tenancy\TenantRegistrationData;
use Nvade\Numerosis\Enums\Tenancy\TenantProvisionStatus;
use Nvade\Numerosis\Events\Billing\CheckoutCompleted;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Models\Central\TenantProvision;

/**
 * Records the subscription against the provision row and queues provisioning.
 *
 * Provisioning is queued whether or not payment settled, only `settled_at`
 * differing, because a trial collects nothing upfront and gating on settlement
 * would be stricter than the trial itself.
 */
class SettleCheckout
{
    use AsAction;

    public function __construct(private readonly ProvisionsTenant $provisioning) {}

    public function handle(
        TenantProvision $pending,
        Subscription $subscription,
        ?string $stripeCustomerId,
        ?string $centralUserId,
    ): void {
        $settled = $subscription->isSettled();
        $stripeSubscriptionId = $subscription->stripe_id;

        $pending->update([
            'stripe_subscription_id' => $stripeSubscriptionId,
            'status' => TenantProvisionStatus::Provisioning,
            'settled_at' => $settled ? now() : null,
        ]);

        $this->provisioning->queue(new TenantProvisionData(
            registration: TenantRegistrationData::fromPending($pending),
            stripeCustomerId: $stripeCustomerId,
            stripeSubscriptionId: $stripeSubscriptionId,
            centralUserId: $centralUserId,
        ));

        event(new CheckoutCompleted($pending->slug, (string) $pending->payment_plan, $stripeSubscriptionId));
    }
}
