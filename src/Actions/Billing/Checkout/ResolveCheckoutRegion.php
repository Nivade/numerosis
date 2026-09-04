<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing\Checkout;

use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * ISO country code for a checkout request's IP, used to order Stripe's
 * Payment Element and pre-fill the Address Element, never to restrict
 * eligibility, which stays Stripe's call.
 *
 * No region lookup is wired in, so this always returns null. Callers must
 * treat null as "use the default order", never as an error.
 *
 * @method static ?string run(Request $request)
 */
class ResolveCheckoutRegion
{
    use AsAction;

    public function handle(Request $request): ?string
    {
        return null;
    }
}
