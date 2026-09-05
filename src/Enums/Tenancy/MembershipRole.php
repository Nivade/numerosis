<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Enums\Tenancy;

/**
 * The `memberships.role` column's vocabulary, a real database enum declared in
 * `2025_06_17_134918_create_tenant_users_table.php`. Unrelated to the
 * tenant-side `roles` table `spatie/laravel-permission` owns.
 *
 * {@see self::assignable()} omits `Owner`, which `AddTenantOwner` grants during
 * provisioning; a second owner row is what `Tenant::owner()` bills.
 */
enum MembershipRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Admin => 'Admin',
            self::Member => 'Member',
            self::Viewer => 'Viewer',
        };
    }

    /**
     * The roles an invitation may carry.
     *
     * @return list<self>
     */
    public static function assignable(): array
    {
        return [self::Admin, self::Member, self::Viewer];
    }
}
