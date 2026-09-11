<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Billing;

use Illuminate\Database\UniqueConstraintViolationException;
use Nvade\Numerosis\Contracts\Billing\SubscriptionRepository;
use Nvade\Numerosis\Data\Billing\SubscriptionData;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Numerosis;
use RuntimeException;

class EloquentSubscriptionRepository implements SubscriptionRepository
{
    public function findByStripeId(string $stripeId): ?Subscription
    {
        $subscriptionClass = Numerosis::model(Subscription::class);

        return $subscriptionClass::query()->where('stripe_id', $stripeId)->first();
    }

    /**
     * `updateOrCreate()` is a select-then-insert, and `CreateInlineSubscription`
     * writes the same `stripe_id` row unlocked from the checkout request, so a
     * `UniqueConstraintViolationException` here means the row landed inside
     * that window. Updating it is what this call would have done had it lost
     * the race by a few more milliseconds.
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
