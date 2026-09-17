<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing\Usage;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Contracts\Billing\SubscriptionRepository;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Models\Central\SubscriptionItem;

/**
 * Copies the meter and the billing period off a Stripe webhook payload onto the
 * local subscription items. Cashier's own handlers write the item rows but
 * none of these four columns, so without this the reporting job has no period
 * to bucket by and no event name to send under.
 *
 * @method static int run(?string $stripeSubscriptionId, list<array<array-key, mixed>> $items)
 */
class SyncMeteredItems
{
    use AsAction;

    public function __construct(private readonly SubscriptionRepository $subscriptions) {}

    /**
     * @param  list<array<array-key, mixed>>  $items  Stripe's `items.data`
     * @return int How many items were touched.
     */
    public function handle(?string $stripeSubscriptionId, array $items): int
    {
        if ($stripeSubscriptionId === null || $items === []) {
            return 0;
        }

        $subscription = $this->subscriptions->findByStripeId($stripeSubscriptionId);

        if (! $subscription instanceof Subscription) {
            return 0;
        }

        $touched = 0;

        foreach ($items as $item) {
            $stripeId = $item['id'] ?? null;

            if (! is_string($stripeId)) {
                continue;
            }

            $local = $subscription->items->first(
                fn (mixed $candidate): bool => $candidate instanceof SubscriptionItem && $candidate->stripe_id === $stripeId,
            );

            if (! $local instanceof SubscriptionItem) {
                continue;
            }

            $local->update([
                'meter_id' => $this->meterId($item) ?? $local->meter_id,
                'current_period_start' => $this->timestamp($item['current_period_start'] ?? null) ?? $local->current_period_start,
                'current_period_end' => $this->timestamp($item['current_period_end'] ?? null) ?? $local->current_period_end,
            ]);

            $touched++;
        }

        StampSubscriptionMeters::run($subscription->refresh());

        return $touched;
    }

    /**
     * @param  array<array-key, mixed>  $item
     */
    private function meterId(array $item): ?string
    {
        $price = $item['price'] ?? null;
        $recurring = is_array($price) ? ($price['recurring'] ?? null) : null;
        $meter = is_array($recurring) ? ($recurring['meter'] ?? null) : null;

        return is_string($meter) && $meter !== '' ? $meter : null;
    }

    private function timestamp(mixed $value): ?string
    {
        return is_int($value) ? now()->setTimestamp($value)->toDateTimeString() : null;
    }
}
