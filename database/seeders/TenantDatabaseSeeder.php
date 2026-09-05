<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Database\Seeders;

use Illuminate\Database\Seeder;
use Nvade\Numerosis\Database\Seeders\Tenant\PermissionAndRoleSeeder;
use Nvade\Numerosis\Database\Seeders\Tenant\UserSeeder;

class TenantDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PermissionAndRoleSeeder::class,
            UserSeeder::class,
        ]);
    }
}
