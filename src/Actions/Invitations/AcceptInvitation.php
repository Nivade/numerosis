<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Invitations;

use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Contracts\Auth\CentralUserModel;
use Nvade\Numerosis\Exceptions\Invitations\InvitationAlreadyAccepted;
use Nvade\Numerosis\Exceptions\Invitations\InvitationExpired;
use Nvade\Numerosis\Exceptions\Invitations\InvitationTenantMismatch;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Tenant\Invitation;
use Nvade\Numerosis\Models\Tenant\User as TenantUser;
use Nvade\Numerosis\Models\User;
use Nvade\Numerosis\Support\Numerosis;

class AcceptInvitation
{
    use AsAction;

    /**
     * Accept the given invitation for the specified user.
     *
     * @throws InvitationAlreadyAccepted
     * @throws InvitationExpired
     * @throws InvitationTenantMismatch
     */
    public function handle(Invitation $invitation, User&CentralUserModel $user): void
    {
        if ($invitation->isAccepted()) {
            throw new InvitationAlreadyAccepted(__('This invitation has already been accepted.'));
        }

        if ($invitation->isExpired()) {
            throw new InvitationExpired(__('This invitation has expired.'));
        }

        $tenantClass = Numerosis::model(Tenant::class);

        /** @var Tenant|null $tenant */
        $tenant = $invitation->tenant ?? $tenantClass::find($invitation->tenant_id);

        if ($tenant === null) {
            throw new InvitationTenantMismatch(__('Invalid tenant for invitation.'));
        }

        // Attach the user to the tenant via the central memberships table.
        $user->tenants()->syncWithoutDetaching([
            $tenant->id => [
                'role' => $invitation->role,
                'joined_at' => now(),
            ],
        ]);

        // Nothing else creates the tenant-side row for an invited member:
        // provisioning only ever does this for owners (Nvade\Numerosis\Actions\Tenancy\
        // AddTenantOwner), and login only ever reads (FindUserByGlobalId),
        // never creates. Without this, accepting via social login throws in
        // LoginUser (no Tenant\User to resolve), and accepting via the
        // password form for someone whose CentralUser already exists
        // elsewhere silently fails the same way.
        $tenantUserClass = Numerosis::model(TenantUser::class);

        $tenant->run(function () use ($user, $invitation, $tenantUserClass): void {
            DB::transaction(function () use ($user, $invitation, $tenantUserClass): void {
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

                $invitation->update(['accepted_at' => now()]);
            });
        });
    }
}
