<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Database\Factories\Central;

use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Models\Central\Tenant;

class SubscriptionFactory extends \Laravel\Cashier\Nvade\Numerosis\Database\Factories\SubscriptionFactory
{
    protected $model = Subscription::class;

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
            'subscribable_type' => Tenant::class,
            'payment_plan_id' => \Nvade\Numerosis\Models\Central\PaymentPlan::factory(),
        ];
    }
}
