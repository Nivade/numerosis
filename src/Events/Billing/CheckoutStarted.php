<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Events\Billing;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched from `Actions\Billing\Checkout\StartSubscriptionCheckout`, once
 * the domain is reserved. No `sessionId`: this package's inline checkout has
 * no Stripe Checkout Session, and the domain is already the stable id a
 * caller resumes a checkout by (see `Actions\Billing\Checkout\ResumeCheckout`).
 */
class CheckoutStarted
{
    use Dispatchable;

    public function __construct(
        public readonly string $domain,
        public readonly string $planId,
    ) {}
}
