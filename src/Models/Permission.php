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
     * `purchase`/`cancel modules` spend and stop the tenant's money, which no
     * CRUD action on the `modules` rows describes — see
     * Nvade\Numerosis\Policies\ModulePolicy. Not in defaultActions() because that list is
     * applied to every context, and `purchase users` is not a thing.
     *
     * @return array<string, list<string>>
     */
    public static function additionalActions(): array
    {
        return [
            'modules' => ['purchase', 'cancel'],
        ];
    }

    /**
     * Every action that exists for a context, in permission-name order.
     *
     * @return list<string>
     */
    public static function actionsFor(string $context): array
    {
        return [
            ...self::defaultActions(),
            ...(self::additionalActions()[$context] ?? []),
        ];
    }
}
