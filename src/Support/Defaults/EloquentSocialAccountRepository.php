<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Support\Defaults;

use Nvade\Numerosis\Contracts\Auth\SocialAccountRepository;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\SocialiteLogin;

class EloquentSocialAccountRepository implements SocialAccountRepository
{
    public function findUserByProviderAndId(string $provider, string $providerId): ?CentralUser
    {
        /** @var CentralUser|null $user */
        $user = SocialiteLogin::where('provider', $provider)
            ->firstWhere('provider_id', $providerId)
            ?->user;

        return $user;
    }
}
