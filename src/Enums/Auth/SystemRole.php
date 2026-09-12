<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Enums\Auth;

/**
 * The spatie `roles.name` value seeded on both guards. Distinct from
 * `MembershipRole`, which is the `memberships.role` column.
 */
enum SystemRole: string
{
    case Admin = 'admin';
}
