<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Billing;

use Illuminate\Http\Request;
use Nvade\Numerosis\Contracts\Billing\CheckoutRegionResolver;

/**
 * Core ships no IP-to-country lookup, so every checkout takes the default
 * payment method order. Bind your own against
 * {@see CheckoutRegionResolver} to change that.
 */
class NullCheckoutRegionResolver implements CheckoutRegionResolver
{
    public function resolve(Request $request): ?string
    {
        return null;
    }
}
