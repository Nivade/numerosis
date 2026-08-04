<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Database\Seeders;

use Nvade\Numerosis\Database\Seeders\Tenant\PermissionAndRoleSeeder;
use Nvade\Numerosis\Database\Seeders\Tenant\UserSeeder;
use Illuminate\Database\Seeder;

class TenantDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PermissionAndRoleSeeder::class,
            UserSeeder::class,
            //            ChatSeeder::class,
        ]);
    }
}
