<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Nvade\Numerosis\Database\Seeders\Central\ModuleOfferingSeeder;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            PaymentPlanSeeder::class,
            RoleAndPermissionSeeder::class,
            ModuleOfferingSeeder::class,
        ]);
    }
}
