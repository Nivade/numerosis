<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Enums\Tenancy;

/**
 * The `memberships.role` column's vocabulary. That column is a real database
 * enum, declared in `2025_06_17_134918_create_tenant_users_table.php`. It has
 * nothing to do with the tenant-side `roles` table
 * `spatie/laravel-permission` owns.
 *
 * {@see self::assignable()} omits `Owner`. `Actions\Tenancy\AddTenantOwner`
 * grants ownership during provisioning. An invitation carrying `owner` would
 * write a second owner row, which `Tenant::owner()` and
 * `DefaultUnpaidTenantQuota` both read as the billing subject.
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
