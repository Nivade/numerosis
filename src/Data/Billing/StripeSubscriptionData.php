<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Data\Billing;

use Illuminate\Support\Collection;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Stripe\Subscription as StripeSubscription;

class StripeSubscriptionData extends Data
{
    /**
     * @param  DataCollection<int, SubscriptionItemData>  $items
     */
    public function __construct(
        public string $id,
        public string $status,
        public ?string $priceId,
        public ?int $quantity,
        public ?string $trialEndsAt,
        public DataCollection $items,
    ) {}

    public static function fromStripe(StripeSubscription $subscription): self
    {
        $items = new Collection($subscription->items->data);
        $firstItem = $items->first();

        return new self(
            id: $subscription->id,
            status: $subscription->status,
            priceId: $firstItem?->price?->id,
            quantity: $firstItem?->quantity,
            trialEndsAt: $subscription->trial_end
                ? now()->setTimestamp($subscription->trial_end)->toDateTimeString()
                : null,
            items: new DataCollection(SubscriptionItemData::class, $items->map(fn ($item) => SubscriptionItemData::from([
                'stripe_id' => $item->id,
                'stripe_price' => $item->price->id,
                'stripe_product' => $item->price->product,
                'quantity' => $item->quantity,
            ]))->all()),
        );
    }
}
