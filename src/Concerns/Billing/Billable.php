<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Concerns\Billing;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Support\Numerosis;

/**
 * Cashier's billable behaviour with a polymorphic subscription relation.
 *
 * Cashier assumes one billable table and gives `subscriptions()` a `HasMany`;
 * here both a `CentralUser` and a `Tenant` can be billed, so
 * {@see \Nvade\Numerosis\Contracts\Subscribable} requires a `MorphMany` instead. The
 * override is declared in this trait's own body rather than resolved with
 * `insteadof`, because the conflicting method is inserted into
 * `Laravel\Cashier\Billable` by a trait it composes
 * (`Laravel\Cashier\Concerns\ManagesSubscriptions`) — a name `insteadof` cannot
 * be given, since its operands have to appear in the same `use`. Naming the
 * aggregate instead works at runtime but leaves the declaration ambiguous to
 * static analysis, which then resolves `subscriptions()` to Cashier's
 * `HasMany` and reports it as incompatible with the interface. A method in the
 * composing trait's body simply wins, with no conflict to resolve.
 */
trait Billable
{
    use \Laravel\Cashier\Billable;

    /**
     * Get all of the subscriptions for the billable model.
     *
     * @return MorphMany<Subscription, $this>
     */
    public function subscriptions(): MorphMany
    {
        return $this->morphMany(Numerosis::model(Subscription::class), 'subscribable')
            ->latest('created_at');
    }

    /**
     * Get the latest subscription for the billable model.
     */
    public function latestSubscription(): ?Subscription
    {
        return $this->subscriptions()
            ->first();
    }

    /**
     * The Stripe customer id, as a `string` rather than a `?string`.
     *
     * `hasStripeId()` does not narrow `stripe_id` for static analysis, so every
     * `Cashier::stripe()->customers->…($model->stripe_id)` call sitting
     * directly behind that guard was reported as `expects string, string|null
     * given` — see .claude/rules/static-analysis.md. Callers that have already
     * established the customer exists use this instead of repeating the guard,
     * and the exception is a genuine invariant breach rather than a
     * domain-expected failure, so it is deliberately not a
     * {@see \Nvade\Numerosis\Contracts\ShowsMessageToUser}.
     *
     * @throws LogicException when no Stripe customer has been created yet
     */
    public function stripeIdOrFail(): string
    {
        $stripeId = $this->stripe_id;

        if (! is_string($stripeId) || $stripeId === '') {
            throw new LogicException(static::class.' has no Stripe customer id. Call createAsStripeCustomer() first.');
        }

        return $stripeId;
    }
}
