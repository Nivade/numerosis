<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Auth;

use Illuminate\Support\Facades\Event;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Events\Auth\SocialAccountDisconnected;
use Nvade\Numerosis\Models\Central\CentralUser;

class DisconnectSocialAccount
{
    use AsAction;

    public function handle(CentralUser $user, string $provider): void
    {
        $deleted = $user->socialiteLogins()
            ->where('provider', $provider)
            ->delete();

        if ($deleted > 0) {
            Event::dispatch(new SocialAccountDisconnected($user, $provider));
        }
    }
}
