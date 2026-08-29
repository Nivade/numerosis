<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Billing\Subscriptions;

use Illuminate\Database\UniqueConstraintViolationException;
use Nvade\Numerosis\Contracts\Billing\SubscriptionRepository;
use Nvade\Numerosis\Data\Billing\SubscriptionData;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Support\Numerosis;
use RuntimeException;

class EloquentSubscriptionRepository implements SubscriptionRepository
{
    public function findByStripeId(string $stripeId): ?Subscription
    {
        $subscriptionClass = Numerosis::model(Subscription::class);

        return $subscriptionClass::query()->where('stripe_id', $stripeId)->first();
    }

    /**
     * `updateOrCreate()` is a plain select-then-insert, not an atomic
     * upsert, and this is one of two writers of a `subscriptions` row for a
     * given `stripe_id`: `CreateInlineSubscription` also inserts one,
     * synchronously and unlocked, from the checkout web request — via
     * Cashier's own `SubscriptionBuilder::create()`, not this repository.
     * The two can't share a lock keyed by `stripe_id`, because Stripe hasn't
     * generated that id yet at the point `CreateInlineSubscription` starts.
     * A live `UniqueConstraintViolationException` here (both inserts firing
     * within the same select-then-insert window) means the row now exists —
     * fall back to the update this call would have taken had it lost the
     * race by a few milliseconds more, instead of surfacing a 500.
     */
    public function record(SubscriptionData $data): Subscription
    {
        $subscriptionClass = Numerosis::model(Subscription::class);

        try {
            $subscription = $subscriptionClass::query()->updateOrCreate(
                ['stripe_id' => $data->stripe_id],
                $data->except('items', 'stripe_id')->toArray(),
            );
        } catch (UniqueConstraintViolationException $e) {
            $subscription = $this->findByStripeId($data->stripe_id);

            if (! $subscription instanceof Subscription) {
                throw new RuntimeException("Subscription {$data->stripe_id} raced on insert but is not findable afterwards", $e->getCode(), previous: $e);
            }

            $subscription->update($data->except('items', 'stripe_id')->toArray());
        }

        foreach ($data->items as $item) {
            $subscription->items()->updateOrCreate(
                ['stripe_id' => $item->stripe_id],
                $item->except('stripe_id')->toArray(),
            );
        }

        return $subscription;
    }
}
