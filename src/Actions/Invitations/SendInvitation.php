<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Invitations;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Data\Invitations\InvitationData;
use Nvade\Numerosis\Events\Invitations\InvitationCreated;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Invitation;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\User;
use Nvade\Numerosis\Support\Numerosis;

/**
 * Re-inviting an address reuses its row, which `unique(tenant_id, email)`
 * requires.
 *
 * `accepted_at`/`accepted_by_user_id` are cleared through `forceFill` because
 * they are deliberately absent from the model's `#[Fillable]`, being state
 * rather than input. An earlier `updateOrCreate` passing them among its values
 * dropped both silently, so re-inviting someone whose first invitation had
 * been accepted minted a link that `AcceptInvitation` then refused with
 * `InvitationAlreadyAccepted`.
 *
 * @method static Invitation run(Tenant $tenant, InvitationData $data, User $inviter)
 */
class SendInvitation
{
    use AsAction;

    public function handle(Tenant $tenant, InvitationData $data, User $inviter): Invitation
    {
        $invitationClass = Numerosis::model(Invitation::class);
        $centralUserClass = Numerosis::model(CentralUser::class);

        // The tenant `User` acting as inviter carries `global_id`, which is
        // what joins it to the central user this row's foreign key names.
        $inviterId = $centralUserClass::query()
            ->where('global_id', $inviter->global_id)
            ->value('id');

        /** @var Invitation $invitation */
        $invitation = $invitationClass::firstOrNew([
            'tenant_id' => $tenant->getKey(),
            'email' => $data->email,
        ]);

        $invitation->forceFill([
            'role' => $data->role,
            'invited_by_user_id' => $inviterId,
            'expires_at' => now()->addDays(7),
            'accepted_at' => null,
            'accepted_by_user_id' => null,
        ])->save();

        event(new InvitationCreated($invitation, $invitation->id));

        return $invitation;
    }
}
