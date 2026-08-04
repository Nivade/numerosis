<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Observers;

use Nvade\Numerosis\Actions\Cache\ForgetUserTenants;
use Nvade\Numerosis\Models\Central\Membership;

class MembershipObserver
{
    public function saved(Membership $membership): void
    {
        ForgetUserTenants::run($membership->global_user_id);
    }

    public function deleted(Membership $membership): void
    {
        ForgetUserTenants::run($membership->global_user_id);
    }
}
