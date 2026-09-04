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

        $user->tenants()->syncWithoutDetaching([
            $tenant->id => [
                'role' => $invitation->role,
                'joined_at' => now(),
            ],
        ]);

        // The only creation site for an invited member's tenant-side row.
        // AddTenantOwner covers owners, and login only reads (FindUserByGlobalId).
        // Without this, accepting via social login throws in LoginUser, and
        // accepting via the password form silently fails the same way when the
        // CentralUser already exists elsewhere.
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
