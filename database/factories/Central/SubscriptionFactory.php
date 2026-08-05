<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Database\Factories\Central;

use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Support\Numerosis;

class SubscriptionFactory extends \Laravel\Cashier\Database\Factories\SubscriptionFactory
{
    /**
     * Cashier's own factory declares `protected $model =
     * \Laravel\Cashier\Subscription::class`, and an *inherited* property still
     * short-circuits `Factory::modelName()`'s resolver — so omitting a
     * `$model` override here does not reach `Numerosis::modelNameFor()`.
     * Left alone, every fixture is built as Cashier's model, which does not
     * compose stancl's `CentralConnection`: the row is written on the
     * *default* connection (inside `RefreshDatabase`'s open transaction) while
     * the application reads and writes `subscriptions` on `central`. The
     * fixture is invisible to the code under test, which then inserts its own
     * row carrying the same unique `stripe_id` and blocks on the uncommitted
     * duplicate key — surfacing as `SQLSTATE 1205 Lock wait timeout`, not as a
     * wrong-model error.
     *
     * @return class-string<\Illuminate\Database\Eloquent\Model>
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
            'user_id' => \Nvade\Numerosis\Models\Central\CentralUser::factory(),
            'type' => 'default',
            'stripe_id' => $this->faker->unique()->lexify('sub_************************'),
            'stripe_status' => 'active',
            'stripe_price' => $this->faker->lexify('price_************************'),
            'quantity' => 1,
            'trial_ends_at' => null,
            'ends_at' => null,
            'subscribable_id' => Tenant::factory(),
            /**
             * The concrete host class, never the abstract one: this string is
             * what Eloquent instantiates when the morph is resolved.
             */
            'subscribable_type' => Numerosis::model(Tenant::class),
            'payment_plan_id' => \Nvade\Numerosis\Models\Central\PaymentPlan::factory(),
        ];
    }
}
