<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Observers\Tenancy;

use Nvade\Numerosis\Actions\Cache\ForgetUserTenants;
use Nvade\Numerosis\Actions\Tenancy\SyncTenantUserForMembership;
use Nvade\Numerosis\Events\Tenancy\MemberJoined;
use Nvade\Numerosis\Events\Tenancy\MemberRemoved;
use Nvade\Numerosis\Models\Central\Membership;

class MembershipObserver
{
    public function saved(Membership $membership): void
    {
        ForgetUserTenants::run($membership->global_user_id);
    }

    public function created(Membership $membership): void
    {
        SyncTenantUserForMembership::run($membership);

        // An attach() with no pivot attributes leaves `role` null on the
        // in-memory model, even though the column defaults to 'member'.
        // Refresh so MemberJoined carries what was actually written.
        $membership->refresh();

        event(new MemberJoined(
            $membership->tenant_id,
            $membership->global_user_id,
            $membership->role,
            $membership->invited_by,
        ));
    }

    public function deleted(Membership $membership): void
    {
        ForgetUserTenants::run($membership->global_user_id);

        event(new MemberRemoved(
            $membership->tenant_id,
            $membership->global_user_id,
            $membership->role,
        ));
    }
}
