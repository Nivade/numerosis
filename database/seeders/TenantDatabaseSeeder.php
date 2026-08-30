<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Database\Seeders;

use Illuminate\Database\Seeder;
use Nvade\Numerosis\Database\Seeders\Tenant\PermissionAndRoleSeeder;
use Nvade\Numerosis\Database\Seeders\Tenant\UserSeeder;
use Nvade\Numerosis\Support\Numerosis;

class TenantDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PermissionAndRoleSeeder::class,
            UserSeeder::class,
            //            ChatSeeder::class,
        ]);

        // Seeders registered via Numerosis::addTenantSeeder() — a satellite
        // package's own tenant tables, without publishing/editing this file.
        $this->call(Numerosis::tenantSeeders());
    }
}
