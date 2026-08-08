<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Billing\Checkout;

use Illuminate\Contracts\Support\Responsable;
use Nvade\Numerosis\Data\Billing\CheckoutIntent;
use Nvade\Numerosis\Data\Billing\Intents\InlineCheckout;
use Nvade\Numerosis\Data\Billing\Intents\RedirectCheckout;
use RuntimeException;

/**
 * Maps a CheckoutIntent onto a Responsable for the HTTP routes that still
 * expect one — StartSubscriptionCheckout::asController and
 * StartLocalCheckout::asController. Temporary: once the inline wizard step
 * consumes CheckoutIntent directly (custom-checkout.md, Phase 2) these routes
 * and this mapper go away with them.
 */
class CheckoutIntentResponse
{
    public static function for(CheckoutIntent $intent): Responsable
    {
        return match (true) {
            $intent instanceof RedirectCheckout => new RedirectResponsable($intent->url),
            $intent instanceof InlineCheckout => throw new RuntimeException(
                'InlineCheckout has no HTTP-redirect representation — the wizard step must consume the CheckoutIntent directly.',
            ),
            default => throw new RuntimeException('Unknown CheckoutIntent variant: '.$intent::class),
        };
    }
}
