<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Database\Factories\Central;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Nvade\Numerosis\Models\Central\CentralUser;

/**
 * @extends Factory<\Nvade\Numerosis\Models\Central\Tenant>
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
            // The id becomes both a subdomain and a physical database name,
            // so it has to satisfy the same charset the wizard enforces; a raw
            // company name leaves unquotable schemas behind on teardown.
            'id' => Str::slug($company),
            // Top-level, never `'data' => ['name' => …]`: VirtualColumn folds
            // non-custom attributes into `data` on save, so passing `data`
            // itself makes it one more virtual attribute and the name is lost.
            'name' => $company,
            'user_id' => CentralUser::factory(),
        ];
    }
}
