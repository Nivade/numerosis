<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Database\Factories\Central;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nvade\Numerosis\Enums\Billing\ModuleBillingMode;
use Nvade\Numerosis\Models\Central\ModuleOffering;

/** @extends \Illuminate\Database\Eloquent\Factories\Factory<\Nvade\Numerosis\Models\Central\ModuleOffering> */
class ModuleOfferingFactory extends Factory
{
    protected $model = ModuleOffering::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        // Cents (minor currency units): $3.00–$50.00.
        $monthlyPrice = $this->faker->numberBetween(300, 5000);

        return [
            'name' => $this->faker->words(2, true),
            'slug' => $this->faker->unique()->slug(),
            'description' => $this->faker->sentence(),
            'billing_mode' => ModuleBillingMode::Recurring,
            'monthly_id' => null,
            'yearly_id' => null,
            'one_time_id' => null,
            'monthly_price' => $monthlyPrice,
            'yearly_price' => $monthlyPrice * 12,
            'one_time_price' => null,
            'available' => true,
        ];
    }

    public function oneTime(): static
    {
        return $this->state(fn (): array => [
            'billing_mode' => ModuleBillingMode::OneTime,
            'monthly_id' => null,
            'yearly_id' => null,
            'monthly_price' => null,
            'yearly_price' => null,
            'one_time_id' => null,
            'one_time_price' => $this->faker->numberBetween(1000, 20000),
        ]);
    }

    public function unavailable(): static
    {
        return $this->state(fn (): array => ['available' => false]);
    }
}
