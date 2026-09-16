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
 * The central and tenant permission seeders build the same graph, one
 * permission per context/action pair granted to that guard's `admin` role,
 * and differ only in guard, connection and action source. The action source
 * is a callback because each guard has its own non-CRUD vocabulary:
 * `impersonate tenants` exists on `web` and nowhere else.
 */
trait SeedsAdminRole
{
    /**
     * `$connection` pins every write to a named connection: `Role` and
     * `Permission` are context-switching models, so a caller seeding central
     * rows has to name `central` explicitly or the row stays locked for the
     * rest of a `RefreshDatabase` test.
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

        // A host with a persistent permission cache otherwise disagrees with
        // the database for the lifetime of every running worker, and spatie
        // throws PermissionDoesNotExist on the miss.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $admin;
    }
}
