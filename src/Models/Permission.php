<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Nvade\Numerosis\Enums\Auth\PermissionAction;
use Nvade\Numerosis\Policies\Auth\PermissionPolicy;

/**
 * @property int $id
 * @property string $name
 * @property string $guard_name
 * @property-read string $ability
 * @property-read string $context
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
class Permission extends \Spatie\Permission\Models\Permission
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    /**
     * @return Attribute<string, never>
     */
    protected function ability(): Attribute
    {
        return Attribute::get(fn (): string => Str::before($this->name, ' '));
    }

    /**
     * @return Attribute<string, never>
     */
    protected function context(): Attribute
    {
        return Attribute::get(fn (): string => Str::afterLast($this->name, ' '));
    }

    /**
     * @return list<PermissionAction>
     */
    public static function defaultActions(): array
    {
        return PermissionAction::cases();
    }

    /**
     * Actions that only make sense for one context, keyed by context. Empty in
     * core, since every context it ships wants exactly
     * {@see self::defaultActions()}. Only the tenant seeder reads it, and a
     * subclass's override only takes effect through a seeder of your own, since
     * both package seeders name this class literally.
     *
     * @see self::actionsFor()
     * @see \Nvade\Numerosis\Database\Seeders\Tenant\PermissionAndRoleSeeder
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
     * Resolves `static::additionalActions()` late, so calling this as
     * `YourPermission::actionsFor()` reaches a subclass's override. That is
     * the only way that seam is reachable at all.
     *
     * @return list<string>
     */
    public static function actionsFor(string $context): array
    {
        return [
            ...array_map(fn (PermissionAction $action): string => $action->value, static::defaultActions()),
            ...(static::additionalActions()[$context] ?? []),
        ];
    }
}
