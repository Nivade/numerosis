<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Database\Seeders\Concerns\SeedsAdminRole;
use Nvade\Numerosis\Models\Permission;

class RoleAndPermissionSeeder extends Seeder
{
    use SeedsAdminRole;

    /**
     * Run the database seeds.
     *
     * Every row here is guard_name 'web', which is the central guard, so
     * writes are pinned to the 'central' connection. Role and Permission are
     * shared, context-switching models (SpatiePermissionsBootstrapper repoints
     * the default connection in tenant context), so seeding through the
     * ambient default connection leaves the just-inserted role row locked for
     * the rest of the test by RefreshDatabase's open transaction. Any later
     * central-connection write needing an FK check against it
     * (PromoteFirstCentralUserToAdmin's model_has_roles insert, via
     * CentralUser's own central connection) then blocks for the full
     * lock_wait_timeout. Seeding via 'central' commits immediately
     * (autocommit), leaving nothing to block on.
     *
     * Actions come from `defaultActions()`, not `actionsFor()`: the
     * `additionalActions()` seam is the tenant seeder's alone.
     */
    public function run(): void
    {
        $this->seedAdminRole(
            $this->contexts(),
            'web',
            fn (): array => Permission::defaultActions(),
            Config::string('tenancy.database.central_connection', 'central'),
        );
    }

    /**
     * Add a context by subclassing and binding your subclass to this class.
     *
     * @return list<string>
     */
    protected function contexts(): array
    {
        return [
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
        ];
    }
}
