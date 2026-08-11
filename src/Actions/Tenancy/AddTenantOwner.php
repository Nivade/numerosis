<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Tenant\User as TenantUser;
use Nvade\Numerosis\Support\Numerosis;

/**
 * Attaches the registering user to the tenant as its owner, and creates
 * their counterpart row inside the tenant database.
 *
 * A provisioning step, idempotent so a retried provision is harmless.
 */
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

        // The only place the owner's tenant-side row is created — login reads
        // it, never creates it. Safe to run unguarded here because the tenant
        // database is created earlier in the provisioning chain.
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
