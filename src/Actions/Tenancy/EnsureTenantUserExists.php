<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Contracts\Auth\CentralUserModel;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Tenant\User as TenantUser;
use Nvade\Numerosis\Models\User;
use Nvade\Numerosis\Support\Numerosis;

/**
 * The one place a tenant-side `User` row is created for a central user who
 * has a membership on that tenant. Called from
 * {@see \Nvade\Numerosis\Observers\MembershipObserver} (the normal path),
 * {@see AddTenantOwner} and
 * {@see \Nvade\Numerosis\Actions\Invitations\AcceptInvitation} (both
 * synchronous, because a login follows immediately), and
 * {@see \Nvade\Numerosis\Listeners\Tenancy\BackfillTenantUsers} (the
 * provisioning-race net).
 *
 * `firstOrCreate` on `global_id` makes a re-run (retried queue job, the
 * backfill listener racing the observer) a no-op. `withoutEvents` stops the
 * tenant `User`'s own `ResourceSyncing` trait from firing a
 * `SyncedResourceSaved` back at the central database, the same guard
 * stancl's `UpdateSyncedResource::updateResourceInTenantDatabases()` uses.
 */
class EnsureTenantUserExists
{
    use AsAction;

    public function handle(Tenant $tenant, User&CentralUserModel $user): void
    {
        $tenantUserClass = Numerosis::model(TenantUser::class);

        $tenant->run(function () use ($user, $tenantUserClass): void {
            $tenantUserClass::withoutEvents(function () use ($user, $tenantUserClass): void {
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
        });
    }
}
