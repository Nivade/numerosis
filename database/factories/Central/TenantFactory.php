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
            // `name` is set as a top-level attribute, never as
            // `'data' => ['name' => …]`. VirtualColumn folds every
            // non-custom attribute into `data` on save, so passing `data`
            // itself makes it just another virtual attribute — the written
            // column came out as {"user_id":…,"tenancy_db_name":…} with no
            // `name` in it at all, and every tenant the suite has ever built
            // had a null name. Nothing failed: the only thing that requires
            // one is Filament's own tenant layout
            // (`FilamentManager::getTenantName(): string`), which no test
            // rendered until the browser suite did.
            'name' => $company,
            'user_id' => \Nvade\Numerosis\Models\Central\CentralUser::factory(),
        ];
    }
}
