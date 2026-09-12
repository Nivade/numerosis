<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Database\Factories\Central;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nvade\Numerosis\Enums\Billing\SubscriptionStatus;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\PaymentPlan;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Numerosis;

/**
 * Extends Laravel's `Factory` directly rather than Cashier's
 * `SubscriptionFactory`. Cashier's declares no `@extends Factory<...>`, so
 * anything inheriting from it resolves its model type as the bare
 * `Model` — which made `Subscription::factory()->make()` statically a
 * `Model` and every call passing that fixture to a `Subscription` parameter
 * a type error. `definition()` and `modelName()` were already fully
 * overridden here, so the only thing given up is Cashier's unused state
 * helpers (`active()`, `trialing()`, `canceled()`, …), none of which this
 * package or its tests call.
 *
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    /**
     * Resolved through `Numerosis::model()` so a host's own subclass is what
     * gets built. This must stay an explicit override rather than a `$model`
     * property: while this factory extended Cashier's, the inherited
     * `protected $model = \Laravel\Cashier\Subscription::class` short-circuited
     * `Factory::modelName()`'s resolver and every fixture was built as
     * Cashier's model, which does not compose stancl's `CentralConnection`.
     * The row then went to the *default* connection (inside `RefreshDatabase`'s
     * open transaction) while the application read and wrote `subscriptions`
     * on `central` — the fixture was invisible to the code under test, which
     * inserted its own row with the same unique `stripe_id` and blocked on the
     * uncommitted duplicate key, surfacing as `SQLSTATE 1205 Lock wait
     * timeout` rather than as a wrong-model error.
     *
     * @return class-string<Subscription>
     */
    public function modelName(): string
    {
        return Numerosis::model(Subscription::class);
    }

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'user_id' => CentralUser::factory(),
            'type' => 'default',
            // lexify() only randomizes `?` placeholders — a `*`-based pattern
            // here previously returned the exact same literal string on
            // every call, so a second Subscription factory call in one test
            // process reliably overflowed the faker->unique() retry budget
            // trying to avoid a "duplicate" that was actually the pattern
            // never having been randomized in the first place.
            'stripe_id' => $this->faker->unique()->lexify('sub_????????????????????????????'),
            'stripe_status' => SubscriptionStatus::Active->value,
            'stripe_price' => $this->faker->lexify('price_????????????????????????????'),
            'quantity' => 1,
            'trial_ends_at' => null,
            'ends_at' => null,
            'subscribable_id' => Tenant::factory(),
            // The concrete host class, never the abstract one: this string is
            // what Eloquent instantiates when the morph is resolved.
            'subscribable_type' => Numerosis::model(Tenant::class),
            'payment_plan_id' => PaymentPlan::factory(),
        ];
    }
}
