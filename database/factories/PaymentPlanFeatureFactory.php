<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Database\Factories;

use Nvade\Numerosis\Models\Central\PaymentPlan;
use Nvade\Numerosis\Models\Central\PaymentPlanFeature;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Nvade\Numerosis\Models\Central\PaymentPlanFeature>
 */
class PaymentPlanFeatureFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = PaymentPlanFeature::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payment_plan_id' => PaymentPlan::factory(),
            'feature_key' => $this->faker->unique()->word(),
            'feature_name' => $this->faker->sentence(2),
            'description' => $this->faker->sentence(),
            'value_type' => $this->faker->randomElement(['boolean', 'integer', 'string']),
            'value' => 'true', // Default to boolean true
            'is_enabled' => true,
            'sort_order' => $this->faker->numberBetween(1, 100),
        ];
    }

    /**
     * Configure the feature as a boolean type.
     *
     * @param  bool  $value  The boolean value
     * @return $this
     */
    public function boolean(bool $value = true): self
    {
        return $this->state(function () use ($value) {
            return [
                'value_type' => 'boolean',
                'value' => $value ? 'true' : 'false',
            ];
        });
    }

    /**
     * Configure the feature as an integer type.
     *
     * @param  int  $value  The integer value
     * @return $this
     */
    public function integer(int $value): self
    {
        return $this->state(function () use ($value) {
            return [
                'value_type' => 'integer',
                'value' => (string) $value,
            ];
        });
    }

    /**
     * Configure the feature as a string type.
     *
     * @param  string  $value  The string value
     * @return $this
     */
    public function string(string $value): self
    {
        return $this->state(function () use ($value) {
            return [
                'value_type' => 'string',
                'value' => $value,
            ];
        });
    }

    /**
     * Configure the feature as disabled.
     *
     * @return $this
     */
    public function disabled(): self
    {
        return $this->state(function () {
            return [
                'is_enabled' => false,
            ];
        });
    }

    /**
     * Set the sort order for the feature.
     *
     * @param  int  $order  The sort order
     * @return $this
     */
    public function sortOrder(int $order): self
    {
        return $this->state(function () use ($order) {
            return [
                'sort_order' => $order,
            ];
        });
    }

    /**
     * Associate the feature with a specific payment plan.
     *
     * @param  int  $planId  The payment plan ID
     * @return $this
     */
    public function forPlan(int $planId): self
    {
        return $this->state(function () use ($planId) {
            return [
                'payment_plan_id' => $planId,
            ];
        });
    }
}
