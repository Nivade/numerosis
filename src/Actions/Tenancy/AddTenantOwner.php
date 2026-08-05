<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Tenant\User as TenantUser;
use Nvade\Numerosis\Support\Numerosis;

// See .claude/rules/tenant-provisioning.md.
class AddTenantOwner
{
    use AsAction;

    public function handle(Tenant $tenant, TenantProvisionData $data): void
    {
        $centralUserClass = Numerosis::model(CentralUser::class);

        /** @var CentralUser $user */
        $user = $centralUserClass::where('global_id', $data->registration->global_id)->firstOrFail();

        if ($user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return;
        }

        $user->tenants()->attach($tenant, [
            'role' => 'owner',
            'joined_at' => now(),
        ]);

        // Nothing else creates the owner's tenant-side row: provisioning has
        // no other step for it, and login only ever reads
        // (FindUserByGlobalId), never creates. Runs here because the tenant
        // database is guaranteed to exist by this point — ProvisionTenant's
        // chain places the database jobs (CreateDatabase/MigrateDatabase/
        // SeedTenantDatabase) before this link — see module-marketplace.md
        // on why $tenant->run() is only safe to use unguarded in contexts
        // like this.
        $tenantUserClass = Numerosis::model(TenantUser::class);

        $tenant->run(function () use ($user, $tenantUserClass): void {
            $tenantUserClass::firstOrCreate(
                ['global_id' => $user->global_id],
                [
                    'name' => $user->name,
                    'email' => $user->email,
                    'password' => $user->password,
                    'email_verified_at' => $user->email_verified_at,
                    'is_bot' => false,
                ],
            );
        });
    }
}
