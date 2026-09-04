<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Data\Invitations;

use Illuminate\Validation\Rule;
use Nvade\Numerosis\Enums\Tenancy\MembershipRole;
use Spatie\LaravelData\Data;

/**
 * `Http\Requests\Invitations\StoreInvitationRequest::rules()` returns
 * {@see self::rules()} verbatim, so the HTTP boundary and any caller reaching
 * {@see self::validateAndCreate()} share one definition. Written separately,
 * the request constrained `role` to three values while this class accepted
 * any string, so a non-HTTP caller could write `owner`.
 */
class InvitationData extends Data
{
    public function __construct(
        public string $email,
        public MembershipRole $role,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'email' => ['required', 'email', 'lowercase'],
            'role' => ['required', Rule::enum(MembershipRole::class)->except(MembershipRole::Owner)],
        ];
    }
}
