<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Data\Billing\Intents;

use Nvade\Numerosis\Data\Billing\CheckoutIntent;

/**
 * Mount a Stripe Payment Element against $clientSecret. $publishableKey rides
 * along so the frontend never has to read it out of config separately from
 * the intent that needs it.
 */
class InlineCheckout extends CheckoutIntent
{
    public function __construct(
        public string $clientSecret,
        public string $publishableKey,
    ) {}
}
