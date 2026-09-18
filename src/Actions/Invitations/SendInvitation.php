<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Invitations;

use Illuminate\Database\UniqueConstraintViolationException;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Contracts\Billing\SeatPolicy;
use Nvade\Numerosis\Data\Invitations\InvitationData;
use Nvade\Numerosis\Events\Invitations\InvitationCreated;
use Nvade\Numerosis\Exceptions\Invitations\SeatLimitReached;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Invitation;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\User;
use Nvade\Numerosis\Numerosis;
use RuntimeException;

/**
 * Re-inviting an address reuses its row, which `unique(tenant_id, email)`
 * requires. `accepted_at`/`accepted_by_user_id` are cleared through
 * `forceFill` because both are deliberately absent from the model's
 * `#[Fillable]`, being state and not input, so passing them to a mass
 * assignment drops them silently.
 *
 * @method static Invitation run(Tenant $tenant, InvitationData $data, User $inviter)
 */
class SendInvitation
{
    use AsAction;

    public function __construct(
        private readonly SeatPolicy $seatPolicy,
    ) {}

    public function handle(Tenant $tenant, InvitationData $data, User $inviter): Invitation
    {
        $invitationClass = Numerosis::model(Invitation::class);
        $centralUserClass = Numerosis::model(CentralUser::class);

        // The tenant `User` acting as inviter carries `global_id`, which is
        // what joins it to the central user this row's foreign key names.
        $inviterId = $centralUserClass::query()
            ->where('global_id', $inviter->global_id)
            ->value('id');

        $identity = [
            'tenant_id' => $tenant->getKey(),
            'email' => $data->email,
        ];

        $state = [
            'role' => $data->role,
            'invited_by_user_id' => $inviterId,
            'expires_at' => now()->addDays(7),
            'accepted_at' => null,
            'accepted_by_user_id' => null,
        ];

        /** @var Invitation $invitation */
        $invitation = $invitationClass::firstOrNew($identity);

        // A row that is still pending already holds a seat, so re-issuing onto
        // it consumes nothing.
        $holdsASeatAlready = $invitation->exists && ! $invitation->isAccepted() && ! $invitation->isExpired();

        throw_unless(
            $holdsASeatAlready || $this->seatPolicy->hasSeatForNewInvitation($tenant),
            SeatLimitReached::class,
            'This workspace has no seats left. Upgrade its plan to invite more members.',
        );

        try {
            $invitation->forceFill($state)->save();
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent invite to the same address won the insert between
            // the read above and this write. Re-issuing onto its row is what
            // this call would have done had it lost the race by more.
            $invitation = $invitationClass::query()->where($identity)->first();

            throw_unless($invitation instanceof Invitation, new RuntimeException("Invitation to {$data->email} raced on insert but is not findable afterwards", 0, previous: $e));

            $invitation->forceFill($state)->save();
        }

        event(new InvitationCreated($invitation, $invitation->id));

        return $invitation;
    }
}
