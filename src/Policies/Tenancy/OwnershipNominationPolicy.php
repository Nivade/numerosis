<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Policies\Tenancy;

use Illuminate\Auth\Access\HandlesAuthorization;
use Nvade\Numerosis\Enums\Tenancy\MembershipRole;
use Nvade\Numerosis\Models\Central\Membership;
use Nvade\Numerosis\Models\Central\OwnershipNomination;
use Nvade\Numerosis\Models\User;
use Stancl\Tenancy\Contracts\Tenant as TenancyTenant;

/**
 * `tenant_ownership_nominations` is central, so binding `{nomination}` on a
 * tenant route resolves any ULID regardless of workspace.
 */
class OwnershipNominationPolicy
{
    use HandlesAuthorization;

    public function delete(User $user, OwnershipNomination $nomination): bool
    {
        $tenant = tenant();

        if (! $tenant instanceof TenancyTenant || $nomination->tenant_id !== (string) $tenant->getTenantKey()) {
            return false;
        }

        return Membership::roleFor($nomination->tenant_id, $user->global_id) === MembershipRole::Owner;
    }
}
