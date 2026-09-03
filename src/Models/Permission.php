<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Nvade\Numerosis\Policies\PermissionPolicy;

/**
 * @property int $id
 * @property string $name
 * @property string $guard_name
 * @property string $ability
 * @property string $context
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Role> $roles
 * @property-read int|null $roles_count
 * @property-read Collection<int, User> $users
 * @property-read int|null $users_count
 *
 * @mixin Model
 */
#[UsePolicy(PermissionPolicy::class)]
#[Fillable([
    'name',
    'guard_name',
])]
#[Guarded([
    'ability',
    'context',
])]
class Permission extends \Spatie\Permission\Models\Permission
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    /**
     * @return list<string>
     */
    public static function defaultActions(): array
    {
        return [
            'viewAny',
            'view',
            'create',
            'updateAny',
            'update',
            'deleteAny',
            'delete',
            'restore',
            'forceDelete',
        ];
    }

    /**
     * Actions that only make sense for one context, keyed by context.
     *
     * Empty in core: every context it ships wants exactly
     * {@see self::defaultActions()}. The seam is kept for a context whose
     * verbs are not CRUD — the module system's `purchase`/`cancel modules`
     * were the original case, deleted 2026-09-03 — and is read through
     * {@see self::actionsFor()}.
     *
     * **Overriding it reaches the tenant guard only.** Only
     * {@see \Nvade\Numerosis\Database\Seeders\Tenant\PermissionAndRoleSeeder}
     * calls `actionsFor()`; the central
     * {@see \Nvade\Numerosis\Database\Seeders\RoleAndPermissionSeeder} calls
     * {@see self::defaultActions()} directly, so a `web`-guard context gets
     * CRUD and nothing else however this is overridden. The asymmetry
     * predates the deletion: `purchase modules` existed on the tenant guard
     * and was never seeded on the central one.
     *
     * **Overriding it also needs a seeder of your own.** This model is not
     * in `numerosis.models` — there is no `Numerosis::model()` indirection to
     * resolve a subclass, and both seeders name this class literally.
     * `actionsFor()` binds late, so a subclass's override applies when *you*
     * call `YourPermission::actionsFor()`, from a seeder registered through
     * {@see \Nvade\Numerosis\Support\Numerosis::addTenantSeeder()}.
     *
     * @return array<string, list<string>>
     */
    public static function additionalActions(): array
    {
        return [];
    }

    /**
     * Every action that exists for a context, in permission-name order.
     *
     * `static::`, not `self::`: called as `YourPermission::actionsFor()` this
     * has to reach a subclass's {@see self::additionalActions()}, which is
     * the only way that seam is reachable at all — see its docblock.
     *
     * @return list<string>
     */
    public static function actionsFor(string $context): array
    {
        return [
            ...static::defaultActions(),
            ...(static::additionalActions()[$context] ?? []),
        ];
    }
}
