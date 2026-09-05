<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Database\Factories\Central;

use Illuminate\Database\Eloquent\Factories\Factory;

// No `protected $model` override: PaymentPlan is abstract. A hardcoded
// $model bypasses Numerosis::modelNameFor()'s resolver, registered globally
// via Factory::guessModelNamesUsing(), so `new static` inside Eloquent's
// create()/make() instantiates the abstract class and throws. The resolver
// routes to the host's concrete stub.
/** @extends \Illuminate\Database\Eloquent\Factories\Factory<\Nvade\Numerosis\Models\Central\PaymentPlan> */
class PaymentPlanFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        // Cents (minor currency units): $10.00–$1000.00.
        $monthlyPrice = $this->faker->numberBetween(1000, 100000);

        return [
            'name' => $this->faker->name(),
            'slug' => $this->faker->slug(),
            'description' => $this->faker->words(9, true),
            'trial_days' => $this->faker->randomNumber(),
            'monthly_id' => null,
            'yearly_id' => null,
            'monthly_price' => $monthlyPrice,
            'yearly_price' => $monthlyPrice * 12,
            'available' => true,
        ];
    }
}
