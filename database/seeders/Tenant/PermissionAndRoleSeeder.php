<?php

declare(strict_types=1);

/*
 * Copyright CWSPS154. All rights reserved.
 * @auth CWSPS154
 * @link  https://github.com/CWSPS154
 */

namespace Nvade\Numerosis\Database\Seeders\Tenant;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use Nvade\Numerosis\Models\Permission;

class PermissionAndRoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $contexts = [
            'invitations',
            'roles',
            'permissions',
            'clients',
            'users',
        ];

        $permissions = collect([]);
        foreach ($contexts as $context) {
            foreach (Permission::actionsFor($context) as $action) {
                $permissions->push(Permission::firstOrCreate([
                    'name' => $action.' '.$context,
                    'guard_name' => 'tenant',
                ]));
            }
        }

        $admin = \Nvade\Numerosis\Models\Role::firstOrCreate([
            'name' => 'admin',
            'guard_name' => 'tenant',
        ]);

        $admin->givePermissionTo($permissions);

        Log::info(\Nvade\Numerosis\Models\Tenant\User::first()?->assignRole($admin));
    }
}
