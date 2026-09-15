<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Numerosis;

/**
 * The `stripe_price` this application last recorded for a Stripe subscription.
 *
 * Read before Cashier's own webhook handler runs, which overwrites the column
 * with the incoming payload's value.
 *
 * @method static ?string run(?string $stripeSubscriptionId)
 */
class FindLocalSubscriptionPrice
{
    use AsAction;

    public function handle(?string $stripeSubscriptionId): ?string
    {
        if ($stripeSubscriptionId === null || $stripeSubscriptionId === '') {
            return null;
        }

        $price = Numerosis::model(Subscription::class)::query()
            ->where('stripe_id', $stripeSubscriptionId)
            ->value('stripe_price');

        return is_string($price) ? $price : null;
    }
}
