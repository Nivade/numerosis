<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Auth\Social;

use Carbon\CarbonImmutable;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
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

        return new SocialUserData(
            provider: $provider,
            providerId: (string) $user->getId(),
            name: $user->getName(),
            email: $user->getEmail(),
            emailVerified: $this->isEmailVerified($provider, $user),
            avatarUrl: $user->getAvatar(),
            token: $user->token ?? null,
            refreshToken: $user->refreshToken ?? null,
            expiresAt: isset($user->expiresIn) ? CarbonImmutable::now()->addSeconds($user->expiresIn) : null,
        );
    }

    /**
     * Per-provider: GitHub and Google expose whether the address was
     * verified, others do not — where it is not knowable, this is `false`.
     * Getting this wrong is the account-takeover vector
     * {@see LoginWithSocialAccount} guards against.
     */
    private function isEmailVerified(SocialProvider $provider, SocialiteUser $user): bool
    {
        $raw = $user->user ?? [];

        return match ($provider) {
            SocialProvider::Google => (bool) ($raw['email_verified'] ?? $raw['verified_email'] ?? false),
            SocialProvider::GitHub => (bool) ($raw['email_verified'] ?? false),
            default => false,
        };
    }
}
