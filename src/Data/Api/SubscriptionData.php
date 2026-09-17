<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Data\Api;

use Nvade\Numerosis\Models\Central\Subscription;
use Spatie\LaravelData\Data;

/**
 * What the tenant is paying for, as Stripe's own status plus the local plan.
 * Carries no Stripe customer or subscription id: an integration has no use for
 * them and a leaked one is an account identifier.
 */
class SubscriptionData extends Data
{
    public function __construct(
        public string $status,
        public bool $active,
        public ?string $plan,
        public ?string $trial_ends_at,
        public ?string $ends_at,
    ) {}

    public static function fromSubscription(Subscription $subscription): self
    {
        return new self(
            status: $subscription->stripe_status,
            active: $subscription->valid(),
            plan: $subscription->paymentPlan?->slug,
            trial_ends_at: $subscription->trial_ends_at?->toIso8601String(),
            ends_at: $subscription->ends_at?->toIso8601String(),
        );
    }
}
