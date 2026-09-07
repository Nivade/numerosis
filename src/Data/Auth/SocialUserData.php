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
     * The profile and credential columns of a `SocialAccount` row, as the
     * create paths in `Actions\Auth\Social` set them.
     *
     * @return array{name: ?string, email: ?string, avatar_url: ?string, token: ?string, refresh_token: ?string, token_expires_at: ?CarbonImmutable}
     */
    public function accountAttributes(): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
            'avatar_url' => $this->avatarUrl,
            ...$this->credentialAttributes(),
        ];
    }

    /**
     * The credential columns alone. A returning login writes only these: a
     * provider that omits a profile field it sent before would otherwise
     * blank the stored copy.
     *
     * @return array{token: ?string, refresh_token: ?string, token_expires_at: ?CarbonImmutable}
     */
    public function credentialAttributes(): array
    {
        return [
            'token' => $this->token,
            'refresh_token' => $this->refreshToken,
            'token_expires_at' => $this->expiresAt,
        ];
    }
}
