<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Auth;

use Illuminate\Support\Facades\Event;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Events\Auth\SocialAccountConnected;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\SocialiteLogin;

/**
 * @method static SocialiteLogin run(CentralUser $user, string $provider, string $providerId)
 */
class ConnectSocialAccount
{
    use AsAction;

    public function handle(CentralUser $user, string $provider, string $providerId): SocialiteLogin
    {
        /** @var SocialiteLogin $socialLogin */
        $socialLogin = SocialiteLogin::firstOrCreate(
            [
                'user_id' => $user->id,
                'provider' => $provider,
            ],
            [
                'provider_id' => $providerId,
            ]
        );

        if ($socialLogin->wasRecentlyCreated) {
            Event::dispatch(new SocialAccountConnected($user, $socialLogin));
        }

        return $socialLogin;
    }
}
