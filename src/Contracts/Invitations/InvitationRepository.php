<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Invitations;

use Nvade\Numerosis\Models\Tenant\Invitation;

/**
 * `Livewire\Invitations\Accept`, `Http\Middleware\CheckInvitationStatus`, and
 * `Nvade\Numerosis\Http\Controllers\Socialite\Login` each look invitations up by token/id
 * directly against `Invitation::where(...)` today. A consumer storing
 * invitations differently (a different key shape, a soft-delete-aware
 * lookup, a cache in front of the query) implements this instead of every
 * call site needing to agree on the query by convention.
 */
interface InvitationRepository
{
    public function findByToken(string $token): ?Invitation;

    public function findOrFailByToken(string $token): Invitation;

    public function find(int $id): ?Invitation;
}
