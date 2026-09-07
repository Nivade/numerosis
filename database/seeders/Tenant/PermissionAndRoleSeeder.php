<?php

declare(strict_types=1);

/*
 * Copyright CWSPS154. All rights reserved.
 * @auth CWSPS154
 * @link  https://github.com/CWSPS154
 */

namespace Nvade\Numerosis\Database\Seeders\Tenant;

use Illuminate\Database\Seeder;
use Nvade\Numerosis\Database\Seeders\Concerns\SeedsAdminRole;
use Nvade\Numerosis\Models\Permission;
use Nvade\Numerosis\Models\Tenant\User;

class PermissionAndRoleSeeder extends Seeder
{
    use SeedsAdminRole;

    /**
     * Run the database seeds.
     *
     * No connection is pinned: this runs inside tenant context, where the
     * default connection is already the tenant's own database.
     */
    public function run(): void
    {
        $admin = $this->seedAdminRole(
            $this->contexts(),
            'tenant',
            fn (string $context): array => Permission::actionsFor($context),
        );

        User::first()?->assignRole($admin);
    }

    /**
     * `clients` was dropped: it had no model, policy or route anywhere in the
     * package, so it seeded nine rows per tenant that nothing could read.
     *
     * Add a context by subclassing and binding your subclass to this class.
     *
     * @return list<string>
     */
    protected function contexts(): array
    {
        return [
            'invitations',
            'roles',
            'permissions',
            'users',
        ];
    }
}
