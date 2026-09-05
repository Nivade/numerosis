<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Concerns\Billing;

use Illuminate\Database\Eloquent\Model;
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
     * @return MorphMany<Subscription, Model>
     */
    public function subscriptions(): MorphMany
    {
        return self::subscriptionsRelation($this);
    }

    /**
     * A `Model`-typed parameter, not `$this` directly, is what makes the
     * returned relation's `TDeclaringModel` match {@see Subscribable::subscriptions()}'s
     * declared `Model` rather than the caller's concrete class.
     *
     * @return MorphMany<Subscription, Model>
     */
    private static function subscriptionsRelation(Model $model): MorphMany
    {
        return $model->morphMany(Numerosis::model(Subscription::class), 'subscribable')
            ->latest('created_at');
    }

    public function latestSubscription(): ?Subscription
    {
        return $this->subscriptions()
            ->first();
    }

    /**
     * The Stripe customer id as a `string`, for callers that have already
     * established the customer exists, since `hasStripeId()` does not narrow
     * the nullable property for static analysis.
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
