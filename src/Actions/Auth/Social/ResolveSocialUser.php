<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Auth\Social;

use Carbon\CarbonImmutable;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as OAuth2User;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Data\Auth\SocialUserData;
use Nvade\Numerosis\Enums\Auth\SocialProvider;

/**
 * The only Socialite touchpoint in the codebase. Everything downstream sees
 * {@see SocialUserData}, never Socialite's own `Laravel\Socialite\Contracts\User`.
 *
 * @method static SocialUserData run(SocialProvider $provider)
 */
class ResolveSocialUser
{
    use AsAction;

    public function handle(SocialProvider $provider): SocialUserData
    {
        $user = Socialite::driver($provider->value)->user();

        // Every `SocialProvider` is OAuth2, so Socialite hands back its
        // `Two\User`, which is where the token and raw-payload properties
        // live; `Contracts\User` declares none of them.
        $oauthUser = $user instanceof OAuth2User ? $user : null;

        return new SocialUserData(
            provider: $provider,
            providerId: (string) $user->getId(),
            name: $user->getName(),
            email: $user->getEmail(),
            emailVerified: $this->isEmailVerified($provider, $user),
            avatarUrl: $user->getAvatar(),
            token: $oauthUser?->token,
            refreshToken: $oauthUser?->refreshToken,
            expiresAt: $oauthUser !== null && $oauthUser->expiresIn !== null
                ? CarbonImmutable::now()->addSeconds($oauthUser->expiresIn)
                : null,
        );
    }

    /**
     * Per-provider, and `false` wherever verification is not knowable.
     * Getting this wrong is the account-takeover vector
     * {@see LoginWithSocialAccount} guards against.
     */
    private function isEmailVerified(SocialProvider $provider, SocialiteUser $user): bool
    {
        $raw = $user instanceof OAuth2User ? $user->user : [];

        return match ($provider) {
            SocialProvider::Google => (bool) ($raw['email_verified'] ?? $raw['verified_email'] ?? false),
            // GitHub's `/user` payload has no verification field. Socialite's
            // driver requests `user:email` and returns only the primary
            // *verified* address, so a non-null one is verified.
            SocialProvider::GitHub => $user->getEmail() !== null,
            default => false,
        };
    }
}
