<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Data\Api;

use Nvade\Numerosis\Models\Central\Invitation;
use Spatie\LaravelData\Data;

/**
 * A pending invitation. Field by field on purpose: the row also carries the
 * token that accepts it, which no integration may read.
 */
class InvitationResource extends Data
{
    public function __construct(
        public ?string $email,
        public string $role,
        public ?string $expires_at,
    ) {}

    public static function fromInvitation(Invitation $invitation): self
    {
        return new self(
            email: $invitation->email,
            role: $invitation->role->value,
            expires_at: $invitation->expires_at?->toIso8601String(),
        );
    }
}
