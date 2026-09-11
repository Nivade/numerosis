<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Responses\Billing;

use Illuminate\Contracts\Support\Responsable;
use Nvade\Numerosis\Data\Billing\CheckoutIntent;
use Nvade\Numerosis\Data\Billing\Intents\InlineCheckout;
use Nvade\Numerosis\Data\Billing\Intents\RedirectCheckout;
use RuntimeException;

/**
 * Turns a {@see CheckoutIntent} into an HTTP response, for the checkout
 * routes. Components embed the checkout component instead and need none of
 * this.
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
