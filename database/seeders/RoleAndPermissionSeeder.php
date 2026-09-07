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
     * One context per policy, and no more: a context nothing authorizes
     * against is nine seeded rows per install that no `can()` ever reads.
     * `domains`, `memberships`, `subscription_items` and
     * `payment_plan_features` were dropped in that audit — none has a policy,
     * a route or a screen. The reverse is the dangerous direction:
     * `.ai/rules/package-boundaries.md` records that a *missing* context is a
     * `PermissionDoesNotExist`, so add the context here before the policy that
     * reads it.
     *
     * Add a context by subclassing and binding your subclass to this class.
     *
     * @return list<string>
     */
    protected function contexts(): array
    {
        return [
            'features',
            'permissions',
            'roles',
            'tenants',
            'subscriptions',
            'payment_plans',
            'users',
        ];
    }
}
