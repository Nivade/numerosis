<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Data\Billing;

use Illuminate\Support\Collection;
use Nvade\Numerosis\Enums\Billing\SubscriptionStatus;
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
        public SubscriptionStatus $status,
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
            status: SubscriptionStatus::from($subscription->status),
            priceId: $firstItem?->price?->id,
            quantity: $firstItem?->quantity,
            trialEndsAt: $subscription->trial_end !== null
                ? now()->setTimestamp($subscription->trial_end)->toDateTimeString()
                : null,
            items: new DataCollection(SubscriptionItemData::class, $items->map(fn ($item) => SubscriptionItemData::from([
                'stripe_id' => $item->id,
                'stripe_price' => $item->price->id,
                'stripe_product' => $item->price->product,
                'quantity' => $item->quantity,
                'meter_id' => self::meterId($item),
                'current_period_start' => self::timestamp($item->current_period_start ?? null),
                'current_period_end' => self::timestamp($item->current_period_end ?? null),
            ]))->all()),
        );
    }

    /** Usage-based prices carry the meter; a licensed price carries nothing here. */
    private static function meterId(mixed $item): ?string
    {
        $meter = is_object($item) ? ($item->price->recurring->meter ?? null) : null;

        return is_string($meter) ? $meter : null;
    }

    private static function timestamp(mixed $value): ?string
    {
        return is_int($value) ? now()->setTimestamp($value)->toDateTimeString() : null;
    }
}
