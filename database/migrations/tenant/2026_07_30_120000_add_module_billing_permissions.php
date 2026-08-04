<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates the two module-billing permissions Nvade\Numerosis\Policies\ModulePolicy checks,
 * and grants them to the `admin` role that Database\Seeders\Tenant\
 * PermissionAndRoleSeeder creates.
 *
 * A data migration rather than a seeder re-run because Spatie's
 * hasPermissionTo() throws PermissionDoesNotExist — a 500, not a false — when
 * no permission of that name exists for the guard (see
 * .claude/rules/auth-guards.md), so every already-provisioned tenant needs
 * these rows before the policy is allowed to ask for them.
 *
 * Names and table names are written out rather than read from
 * Nvade\Numerosis\Models\Permission::additionalActions() or config('permission'): a
 * migration is a snapshot, and must keep doing exactly this if either changes.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $permissions = [
        'purchase modules',
        'cancel modules',
    ];

    public function up(): void
    {
        $connection = DB::connection($this->getConnection());
        $now = now();

        foreach ($this->permissions as $name) {
            $exists = $connection->table('permissions')
                ->where('name', $name)
                ->where('guard_name', 'tenant')
                ->exists();

            if (! $exists) {
                $connection->table('permissions')->insert([
                    'name' => $name,
                    'guard_name' => 'tenant',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        /** @var int|null $adminRoleId */
        $adminRoleId = $connection->table('roles')
            ->where('name', 'admin')
            ->where('guard_name', 'tenant')
            ->value('id');

        if ($adminRoleId !== null) {
            /** @var list<int> $permissionIds */
            $permissionIds = $connection->table('permissions')
                ->whereIn('name', $this->permissions)
                ->where('guard_name', 'tenant')
                ->pluck('id')
                ->all();

            foreach ($permissionIds as $permissionId) {
                $connection->table('role_has_permissions')->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'role_id' => $adminRoleId,
                ]);
            }
        }

        $this->forgetCachedPermissions();
    }

    public function down(): void
    {
        $connection = DB::connection($this->getConnection());

        /** @var list<int> $permissionIds */
        $permissionIds = $connection->table('permissions')
            ->whereIn('name', $this->permissions)
            ->where('guard_name', 'tenant')
            ->pluck('id')
            ->all();

        if ($permissionIds !== []) {
            $connection->table('role_has_permissions')
                ->whereIn('permission_id', $permissionIds)
                ->delete();

            $connection->table('permissions')
                ->whereIn('id', $permissionIds)
                ->delete();
        }

        $this->forgetCachedPermissions();
    }

    /**
     * Spatie caches the whole permission set, so rows written behind its back
     * stay invisible until the cache is dropped.
     */
    private function forgetCachedPermissions(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
