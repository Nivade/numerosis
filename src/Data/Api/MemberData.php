<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Data\Api;

use Nvade\Numerosis\Models\Central\Membership;
use Spatie\LaravelData\Data;

/**
 * A member of the tenant the token belongs to. Field by field on purpose: a
 * user model carries the two-factor columns and the anonymisation stamp, none of
 * which belongs in an integration's payload.
 */
class MemberData extends Data
{
    public function __construct(
        public string $global_id,
        public ?string $name,
        public ?string $email,
        public string $role,
        public ?string $joined_at,
    ) {}

    public static function fromMembership(Membership $membership): self
    {
        return new self(
            global_id: $membership->global_user_id,
            name: $membership->user?->name,
            email: $membership->user?->email,
            role: $membership->role->value,
            joined_at: $membership->joined_at?->toIso8601String(),
        );
    }
}
