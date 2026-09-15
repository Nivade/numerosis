<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Billing;

use Illuminate\Http\Request;

interface CheckoutRegionResolver
{
    /**
     * ISO country code for a checkout request, used to order Stripe's Payment
     * Element and pre-fill the Address Element, never to restrict
     * eligibility, which stays Stripe's call. Null means "use the default
     * order" and is not an error.
     */
    public function resolve(Request $request): ?string;
}
