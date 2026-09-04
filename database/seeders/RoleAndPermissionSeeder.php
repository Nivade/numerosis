<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Models\Permission;
use Nvade\Numerosis\Models\Role;
use Nvade\Numerosis\Support\Numerosis;
use Spatie\Permission\PermissionRegistrar;

class RoleAndPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Every row here is guard_name 'web', which is the central guard, so
     * writes are pinned to the 'central' connection with ::on(). Role and
     * Permission are shared, context-switching models
     * (SpatiePermissionsBootstrapper repoints the default connection in
     * tenant context), so seeding through the ambient default connection
     * leaves the just-inserted role row locked for the rest of the test by
     * RefreshDatabase's open transaction. Any later central-connection write
     * needing an FK check against it (PromoteFirstCentralUserToAdmin's
     * model_has_roles insert, via CentralUser's own central connection) then
     * blocks for the full lock_wait_timeout. Seeding via 'central' commits
     * immediately (autocommit), leaving nothing to block on.
     */
    public function run(): void
    {
        $central = Config::string('tenancy.database.central_connection', 'central');

        $contexts = [
            'domains',
            'features',
            'memberships',
            'permissions',
            'roles',
            'tenants',
            'subscriptions',
            'subscription_items',
            'payment_plans',
            'payment_plan_features',
            'users',
            ...Numerosis::permissionContexts(),
        ];

        $permissions = collect([]);
        foreach ($contexts as $context) {
            foreach (Permission::defaultActions() as $action) {
                $permissions->push(Permission::on($central)->firstOrCreate([
                    'name' => $action.' '.$context,
                    'guard_name' => 'web',
                ]));
            }
        }

        $admin = Role::on($central)->firstOrCreate([
            'name' => 'admin',
            'guard_name' => 'web',
        ]);

        $admin->givePermissionTo($permissions);

        // A host with a persistent permission cache store (Redis, database)
        // otherwise disagrees with the DB on what permissions exist, for the
        // lifetime of every already-running worker, after any reseed that
        // adds or renames a permission. Spatie throws PermissionDoesNotExist
        // on a miss, so that reads as a navigation-wide `viewAny` 500ing
        // every page.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
