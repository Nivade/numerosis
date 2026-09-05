<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Data\Auth;

use Carbon\CarbonImmutable;
use Nvade\Numerosis\Enums\Auth\SocialProvider;
use SensitiveParameter;
use Spatie\LaravelData\Data;

/**
 * The only shape anything downstream of `ResolveSocialUser` sees. Socialite's
 * own `Laravel\Socialite\Contracts\User` must not leak past that action.
 * `$emailVerified` is `false` for any provider that cannot prove the address,
 * which is the account-takeover vector `LoginWithSocialAccount` guards.
 *
 * @see \Nvade\Numerosis\Actions\Auth\Social\ResolveSocialUser
 * @see \Nvade\Numerosis\Actions\Auth\Social\LoginWithSocialAccount
 */
class SocialUserData extends Data
{
    public function __construct(
        public SocialProvider $provider,
        public string $providerId,
        public ?string $name,
        public ?string $email,
        public bool $emailVerified,
        public ?string $avatarUrl,
        #[SensitiveParameter]
        public ?string $token,
        #[SensitiveParameter]
        public ?string $refreshToken,
        public ?CarbonImmutable $expiresAt,
    ) {}

    /**
     * The profile and credential columns of a `SocialAccount` row, as every
     * write path in `Actions\Auth\Social` sets them.
     *
     * @return array{name: ?string, email: ?string, avatar_url: ?string, token: ?string, refresh_token: ?string, token_expires_at: ?CarbonImmutable}
     */
    public function accountAttributes(): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
            'avatar_url' => $this->avatarUrl,
            'token' => $this->token,
            'refresh_token' => $this->refreshToken,
            'token_expires_at' => $this->expiresAt,
        ];
    }
}
