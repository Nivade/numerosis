<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Support;

use App\Models\Central\Subscription;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Cashier\SubscriptionItem;

/**
 * A persisted subscription row whose `swapAndInvoice()` skips the Stripe call.
 * Named so the PHPStan baseline pins it by class name; the anonymous form it
 * replaced pinned by line number and broke on any edit above it.
 */
#[Table(name: 'subscriptions')]
class SubscriptionWithoutStripe extends Subscription
{
    /**
     * Named explicitly because Eloquent would otherwise guess
     * `subscription_without_stripe_id` off this class's basename, and Cashier
     * eager-loads `items` on every read.
     *
     * @return HasMany<SubscriptionItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(SubscriptionItem::class, 'subscription_id');
    }

    /**
     * @param  string|array<array-key, mixed>  $prices
     * @param  array<array-key, mixed>  $options
     */
    public function swapAndInvoice($prices, $options = []): static
    {
        return $this;
    }
}
