<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Support\Defaults;

use Nvade\Numerosis\Contracts\Invitations\InvitationRepository;
use Nvade\Numerosis\Models\Tenant\Invitation;

class EloquentInvitationRepository implements InvitationRepository
{
    public function findByToken(string $token): ?Invitation
    {
        return Invitation::where('token', $token)->first();
    }

    public function findOrFailByToken(string $token): Invitation
    {
        return Invitation::where('token', $token)->firstOrFail();
    }

    public function find(int $id): ?Invitation
    {
        return Invitation::find($id);
    }
}
