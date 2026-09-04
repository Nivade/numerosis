<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Auth\Social;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Data\Auth\SocialUserData;
use Nvade\Numerosis\Events\Auth\SocialAccountLinked;
use Nvade\Numerosis\Exceptions\Auth\SocialAccountAlreadyLinked;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\SocialAccount;
use Nvade\Numerosis\Support\Numerosis;

/**
 * The authed path, attaching the identity to the already-logged-in user.
 *
 * An identity already held by a different account throws
 * `SocialAccountAlreadyLinked`. The table's `unique(provider, provider_id)`
 * still backstops the concurrent case, but reaching that through the happy
 * path surfaced as an uncaught `QueryException`, making the collision a 500.
 *
 * @method static SocialAccount run(CentralUser $user, SocialUserData $data)
 */
class LinkSocialAccount
{
    use AsAction;

    public function handle(CentralUser $user, SocialUserData $data): SocialAccount
    {
        $socialAccountClass = Numerosis::model(SocialAccount::class);

        $claimedByOther = $socialAccountClass::query()
            ->where('provider', $data->provider)
            ->where('provider_id', $data->providerId)
            ->where('user_id', '!=', $user->getKey())
            ->exists();

        throw_if(
            $claimedByOther,
            SocialAccountAlreadyLinked::class,
            'That '.$data->provider->label().' account is already connected to another user.',
        );

        $socialAccount = $socialAccountClass::updateOrCreate(
            ['user_id' => $user->getKey(), 'provider' => $data->provider],
            [
                'provider_id' => $data->providerId,
                'name' => $data->name,
                'email' => $data->email,
                'avatar_url' => $data->avatarUrl,
                'token' => $data->token,
                'refresh_token' => $data->refreshToken,
                'token_expires_at' => $data->expiresAt,
            ],
        );

        event(new SocialAccountLinked($socialAccount, $user->global_id, $data->provider->value));

        return $socialAccount;
    }
}
