<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Invitations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Actions\Tenancy\AddTenantMember;
use Nvade\Numerosis\Events\Invitations\InvitationAccepted;
use Nvade\Numerosis\Exceptions\Invitations\InvitationAlreadyAccepted;
use Nvade\Numerosis\Exceptions\Invitations\InvitationEmailMismatch;
use Nvade\Numerosis\Exceptions\Invitations\InvitationExpired;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Invitation;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Support\Numerosis;

/**
 * Runs entirely on the central connection.
 *
 * Acceptance is claimed with a conditional `UPDATE ... WHERE accepted_at IS
 * NULL` before the membership is attached, so two concurrent POSTs cannot both
 * reach `AddTenantMember`. The loser matches zero rows and throws
 * `InvitationAlreadyAccepted`, rolling back inside this transaction. Reading
 * `isAccepted()` and stamping afterwards left a window where both passed and
 * the second attach died on `memberships.unique(tenant_id, global_user_id)`
 * with an uncaught `QueryException`.
 *
 * @method static Invitation run(Invitation $invitation, CentralUser $user)
 */
class AcceptInvitation
{
    use AsAction;

    public function handle(Invitation $invitation, CentralUser $user): Invitation
    {
        return DB::transaction(function () use ($invitation, $user): Invitation {
            throw_if($invitation->isAccepted(), InvitationAlreadyAccepted::class, 'This invitation has already been accepted.');
            throw_if($invitation->isExpired(), InvitationExpired::class, 'This invitation has expired.');
            throw_unless(
                Str::lower($user->email) === Str::lower($invitation->email),
                InvitationEmailMismatch::class,
                'This invitation was sent to a different email address.',
            );

            $invitationClass = Numerosis::model(Invitation::class);

            $claimed = $invitationClass::query()
                ->whereKey($invitation->getKey())
                ->whereNull('accepted_at')
                ->update([
                    'accepted_at' => now(),
                    'accepted_by_user_id' => $user->getKey(),
                ]);

            throw_if($claimed === 0, InvitationAlreadyAccepted::class, 'This invitation has already been accepted.');

            $invitation->refresh();

            $tenantClass = Numerosis::model(Tenant::class);
            $tenant = $tenantClass::findOrFail($invitation->tenant_id);

            $centralUserClass = Numerosis::model(CentralUser::class);
            $inviterGlobalId = $invitation->invited_by_user_id !== null
                ? $centralUserClass::find($invitation->invited_by_user_id)?->global_id
                : null;

            AddTenantMember::run($tenant, $user, $invitation->role, $inviterGlobalId);

            event(new InvitationAccepted(
                $invitation,
                $invitation->id,
                $invitation->invited_by_user_id,
                (int) $invitation->created_at?->diffInSeconds(now()),
            ));

            return $invitation;
        });
    }
}
