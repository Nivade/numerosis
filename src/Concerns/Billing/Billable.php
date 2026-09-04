<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Concerns\Billing;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Support\Numerosis;

/**
 * Cashier's billable behaviour with a polymorphic subscription relation, since
 * both central users and tenants can be billed: `subscriptions()` returns a
 * `MorphMany` where Cashier assumes one billable table and returns `HasMany`.
 * That override must be declared in this trait's own body, because `insteadof`
 * cannot name a method Cashier inserts through a trait of its own.
 */
trait Billable
{
    use \Laravel\Cashier\Billable;

    /**
     * @return MorphMany<Subscription, $this>
     */
    public function subscriptions(): MorphMany
    {
        return $this->morphMany(Numerosis::model(Subscription::class), 'subscribable')
            ->latest('created_at');
    }

    public function latestSubscription(): ?Subscription
    {
        return $this->subscriptions()
            ->first();
    }

    /**
     * The Stripe customer id as a `string`, for callers that have already
     * established the customer exists — `hasStripeId()` does not narrow the
     * nullable property for static analysis.
     *
     * The exception is an invariant breach, never something to show a user.
     *
     * @throws LogicException when no Stripe customer has been created yet
     */
    public function stripeIdOrFail(): string
    {
        $stripeId = $this->stripe_id;

        throw_if(! is_string($stripeId) || $stripeId === '', LogicException::class, static::class.' has no Stripe customer id. Call createAsStripeCustomer() first.');

        return $stripeId;
    }
}
