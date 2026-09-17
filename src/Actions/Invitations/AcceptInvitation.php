<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Invitations;

use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Actions\Tenancy\AddTenantMember;
use Nvade\Numerosis\Contracts\Billing\SeatPolicy;
use Nvade\Numerosis\Events\Invitations\InvitationAccepted;
use Nvade\Numerosis\Exceptions\Invitations\InvitationAlreadyAccepted;
use Nvade\Numerosis\Exceptions\Invitations\InvitationEmailMismatch;
use Nvade\Numerosis\Exceptions\Invitations\SeatLimitReached;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Invitation;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Numerosis;

/**
 * Runs entirely on the central connection, and takes its transaction on that
 * connection: the default one is a different PDO handle with its own
 * transaction stack. Acceptance is claimed with a conditional
 * `UPDATE ... WHERE accepted_at IS NULL`, so of two concurrent POSTs the loser
 * matches zero rows and throws `InvitationAlreadyAccepted`.
 *
 * @method static Invitation run(Invitation $invitation, CentralUser $user)
 */
class AcceptInvitation
{
    use AsAction;

    public function __construct(
        private readonly SeatPolicy $seatPolicy,
    ) {}

    public function handle(Invitation $invitation, CentralUser $user): Invitation
    {
        return $invitation->getConnection()->transaction(function () use ($invitation, $user): Invitation {
            $invitation->assertClaimable();

            // An account with no address of its own matches no invitation.
            throw_unless(
                $user->email !== null && Str::lower($user->email) === Str::lower($invitation->email),
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

            // Counted after the claim, so this row no longer counts itself.
            // The throw rolls the claim back and the invitation stays
            // acceptable once the tenant has made room.
            throw_unless(
                $this->seatPolicy->hasSeatForNewMember($tenant),
                SeatLimitReached::class,
                'This workspace has no seats left. Ask an administrator to upgrade its plan, then open this invitation again.',
            );

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
