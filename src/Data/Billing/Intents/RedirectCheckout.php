<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Data\Billing\Intents;

use Nvade\Numerosis\Data\Billing\CheckoutIntent;

/**
 * Send the browser to $url: hosted Stripe Checkout today, the local dev
 * shortcut always.
 */
class RedirectCheckout extends CheckoutIntent
{
    public function __construct(
        public string $url,
    ) {}
}
