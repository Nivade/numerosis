<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Nvade\Numerosis\Database\Seeders\Central\ModuleOfferingSeeder;
use Nvade\Numerosis\Support\Numerosis;

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
            ...Numerosis::centralSeeders(),
        ]);
    }
}
