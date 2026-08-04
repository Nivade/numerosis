<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Database\Factories\Central;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Nvade\Numerosis\Models\Central\Feature>
 */
class FeatureFactory extends Factory
{
    protected $model = \Nvade\Numerosis\Models\Central\Feature::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => $this->faker->slug(),
            'description' => $this->faker->sentence(),
        ];
    }
}
