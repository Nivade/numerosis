<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Database\Factories\Central;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Nvade\Numerosis\Models\Central\Tenant>
 */
class TenantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $company = $this->faker->unique()->company();

        return [
            // The id becomes both a subdomain and a physical database name, so
            // it has to satisfy the same charset the registration wizard
            // enforces. Raw company names contain spaces, commas and
            // apostrophes, which produced unquotable database names and left
            // orphaned schemas behind on teardown.
            'id' => \Illuminate\Support\Str::slug($company),
            'data' => [
                'name' => $company,
            ],
            'user_id' => \Nvade\Numerosis\Models\Central\CentralUser::factory(),
        ];
    }
}
