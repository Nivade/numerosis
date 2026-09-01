<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Invitations;

use Nvade\Numerosis\Contracts\Invitations\InvitationRepository;
use Nvade\Numerosis\Models\Tenant\Invitation;
use Nvade\Numerosis\Support\Numerosis;

class EloquentInvitationRepository implements InvitationRepository
{
    public function findByToken(string $token): ?Invitation
    {
        $invitationClass = Numerosis::model(Invitation::class);

        /** @var Invitation|null */
        return $invitationClass::where('token', $token)->first();
    }

    public function findOrFailByToken(string $token): Invitation
    {
        $invitationClass = Numerosis::model(Invitation::class);

        /** @var Invitation */
        return $invitationClass::where('token', $token)->firstOrFail();
    }

    public function find(int $id): ?Invitation
    {
        $invitationClass = Numerosis::model(Invitation::class);

        /** @var Invitation|null */
        return $invitationClass::find($id);
    }
}
