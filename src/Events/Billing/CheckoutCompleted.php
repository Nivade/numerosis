<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Events\Billing;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched from `Actions\Billing\Checkout\SettleCheckout`, the one point
 * every checkout path funnels through: the redirect return route, the
 * `payment_method.attached` webhook, and the card/Link Livewire path all
 * call it. Fires whether or not the subscription settled immediately, since
 * a trial collects nothing upfront. `SettleCheckout`'s docblock has the rest.
 */
class CheckoutCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly string $domain,
        public readonly string $planId,
        public readonly ?string $stripeSubscriptionId,
    ) {}
}
