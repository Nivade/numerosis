<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Auth;

use Nvade\Numerosis\Models\Central\CentralUser;

/**
 * `Nvade\NumerosisAuthUi\Http\Controllers\Socialite\Login` resolves an existing social login by
 * querying `SocialiteLogin::where('provider', ...)->firstWhere('provider_id',
 * ...)` directly. A consumer storing OAuth identities differently (a
 * separate `social_accounts` table shape, an external identity provider)
 * implements this instead of that one controller being the sole place the
 * lookup shape is assumed correct.
 */
interface SocialAccountRepository
{
    public function findUserByProviderAndId(string $provider, string $providerId): ?CentralUser;
}
