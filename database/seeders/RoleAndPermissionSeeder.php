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
     * Every row here is guard_name 'web' — central-guard by definition
     * (.claude/rules/auth-guards.md: "web IS the central guard") — so writes
     * are pinned to the 'central' connection explicitly via ::on(), rather
     * than left on Role/Permission's ambient default connection. Role and
     * Permission are shared, context-switching models (SpatiePermissionsBootstrapper
     * repoints the default connection in tenant context), so under
     * RefreshDatabase's open, uncommitted transaction on the default
     * connection, seeding via that connection leaves the just-inserted role
     * row locked for the rest of the test — any later central-connection
     * write needing an FK check against it (e.g. PromoteFirstCentralUserToAdmin's
     * model_has_roles insert, via CentralUser's own central connection) then
     * blocks for the full lock_wait_timeout. Seeding via 'central' directly
     * commits immediately (autocommit), so there is nothing left to block on.
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
        // otherwise disagrees with the DB on what permissions exist for the
        // lifetime of every already-running worker after any reseed that
        // adds or renames a permission — see .ai/rules/auth-guards.md's
        // "Missing permission row = 500, not 403" bullet, which documents
        // this biting a real host (a navigation-wide `viewAny` 500ing every
        // page) because nothing here cleared the cache after writing rows.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
