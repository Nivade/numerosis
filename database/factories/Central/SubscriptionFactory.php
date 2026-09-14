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
 * Extends Laravel's `Factory`, not Cashier's `SubscriptionFactory`, which
 * declares no `@extends Factory<...>` and so resolves its model type as the
 * bare `Model`.
 *
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    /**
     * Resolved through `Numerosis::model()` so a host's own subclass is what
     * gets built. Must stay an explicit override and never a `$model`
     * property, which short-circuits the resolver and builds Cashier's model
     * on the default connection instead.
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
            // lexify() randomizes `?` placeholders only: a `*`-based pattern
            // returns the same literal every call, which overflows the
            // faker->unique() retry budget on the second call in a process.
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
