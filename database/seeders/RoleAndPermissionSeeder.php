<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Database\Seeders\Concerns\SeedsAdminRole;
use Nvade\Numerosis\Enums\Auth\PermissionAction;
use Nvade\Numerosis\Enums\Auth\PermissionContext;
use Nvade\Numerosis\Models\Permission;
use Nvade\Numerosis\Policies\Tenancy\TenantPolicy;

class RoleAndPermissionSeeder extends Seeder
{
    use SeedsAdminRole;

    /**
     * Every row here is guard `web`, so writes are pinned to the `central`
     * connection: `Role` and `Permission` are context-switching models, and
     * seeding them through the ambient default leaves the new row locked for
     * the rest of a `RefreshDatabase` test.
     */
    public function run(): void
    {
        $this->seedAdminRole(
            $this->contexts(),
            'web',
            fn (string $context): array => [
                ...array_map(fn (PermissionAction $action): string => $action->value, Permission::defaultActions()),
                ...($this->additionalActions()[$context] ?? []),
            ],
            Config::string('tenancy.database.central_connection', 'central'),
        );
    }

    /**
     * Non-CRUD verbs for the central guard, keyed by context, and the mirror
     * of {@see self::contexts()}: guard is decided here, so the per-guard
     * vocabulary belongs here too. `Permission::additionalActions()` is the
     * tenant guard's source and stays that way — a map shared by both would
     * seed `impersonate tenants` into every tenant database, where nothing
     * reads it.
     *
     * `impersonate tenants` is seeded whether or not
     * {@see \Nvade\Numerosis\Features\Admin\ImpersonationFeature} is enabled,
     * because spatie throws `PermissionDoesNotExist` for a name that does not
     * exist rather than denying.
     *
     * @return array<string, list<string>>
     */
    protected function additionalActions(): array
    {
        return [
            PermissionContext::Tenants->value => [TenantPolicy::IMPERSONATE],
        ];
    }

    /**
     * One context per policy, and no more: a context nothing authorizes
     * against is nine seeded rows per install no `can()` ever reads, while a
     * missing one throws `PermissionDoesNotExist`. Add a context by
     * subclassing and binding your subclass to this class.
     *
     * @return list<string>
     */
    protected function contexts(): array
    {
        return array_map(fn (PermissionContext $context): string => $context->value, [
            PermissionContext::Features,
            PermissionContext::Permissions,
            PermissionContext::Roles,
            PermissionContext::Tenants,
            PermissionContext::Subscriptions,
            PermissionContext::PaymentPlans,
            PermissionContext::Users,
        ]);
    }
}
