<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\SocialAccount;

/**
 * No lockout on unlink: a user with no password and only one connected
 * account must not be able to lock themselves out of their own account by
 * disconnecting their only way in.
 */
class SocialAccountPolicy
{
    use HandlesAuthorization;

    public function delete(CentralUser $user, SocialAccount $socialAccount): bool
    {
        if (! $user->is($socialAccount->user)) {
            return false;
        }

        if (filled($user->password)) {
            return true;
        }

        return $user->socialAccounts()->count() > 1;
    }
}
