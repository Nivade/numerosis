<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Database\Seeders\Concerns;

use Closure;
use Illuminate\Support\Collection;
use Nvade\Numerosis\Enums\Auth\SystemRole;
use Nvade\Numerosis\Models\Permission;
use Nvade\Numerosis\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The central and tenant permission seeders build the same graph — one
 * permission per context/action pair, all of them granted to that guard's
 * `admin` role — and differ only in guard, connection, and where the action
 * list comes from. Those three are parameters here rather than a second copy
 * of the loop.
 *
 * The action source stays a callback on purpose: the tenant seeder resolves
 * through `Permission::actionsFor()` so a subclass's `additionalActions()`
 * is honoured, while the central seeder deliberately does not. See
 * {@see Permission::additionalActions()}.
 */
trait SeedsAdminRole
{
    /**
     * `$connection` pins every write to a named connection. Role and
     * Permission are shared, context-switching models, so a caller seeding
     * central rows has to name `central` explicitly — {@see \Nvade\Numerosis\Database\Seeders\RoleAndPermissionSeeder}
     * for the lock-wait timeout that follows when it does not.
     *
     * @param  list<string>  $contexts
     * @param  Closure(string): list<string>  $actionsFor
     */
    protected function seedAdminRole(array $contexts, string $guard, Closure $actionsFor, ?string $connection = null): Role
    {
        /** @var Collection<int, Permission> $permissions */
        $permissions = collect([]);

        foreach ($contexts as $context) {
            foreach ($actionsFor($context) as $action) {
                $query = $connection === null ? Permission::query() : Permission::on($connection);

                $permissions->push($query->firstOrCreate([
                    'name' => $action.' '.$context,
                    'guard_name' => $guard,
                ]));
            }
        }

        $roles = $connection === null ? Role::query() : Role::on($connection);

        $admin = $roles->firstOrCreate([
            'name' => SystemRole::Admin->value,
            'guard_name' => $guard,
        ]);

        $admin->givePermissionTo($permissions);

        // A host with a persistent permission cache store (Redis, database)
        // otherwise disagrees with the DB on what permissions exist, for the
        // lifetime of every already-running worker, after any reseed that
        // adds or renames a permission. Spatie throws PermissionDoesNotExist
        // on a miss, so that reads as a navigation-wide `viewAny` 500ing
        // every page.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $admin;
    }
}
