<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Data\Auth;

use Carbon\CarbonImmutable;
use Nvade\Numerosis\Enums\Auth\SocialProvider;
use SensitiveParameter;
use Spatie\LaravelData\Data;

/**
 * The only shape anything downstream of {@see \Nvade\Numerosis\Actions\Auth\Social\ResolveSocialUser}
 * sees — Socialite's own `Laravel\Socialite\Contracts\User` must not leak
 * past that action.
 *
 * `$emailVerified` is per-provider: GitHub and Google expose it, others do
 * not, and where it is not knowable this is `false`. Getting this wrong is
 * the account-takeover vector documented on
 * {@see \Nvade\Numerosis\Actions\Auth\Social\LoginWithSocialAccount}.
 *
 * `$token`/`$refreshToken` are the provider's live credentials, plaintext
 * until `SocialAccount`'s `encrypted` cast. Both carry
 * `#[SensitiveParameter]` so they stay out of stack traces. Sentry is wired
 * here (`Concerns\TagsSentryScopeWithTenant`), so an unmarked argument leaves
 * the machine on the next throw.
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
}
