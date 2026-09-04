<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Auth\Social;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Actions\Auth\LoginUser;
use Nvade\Numerosis\Data\Auth\SocialUserData;
use Nvade\Numerosis\Events\Auth\SocialAccountLinked;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\SocialAccount;
use Nvade\Numerosis\Support\Numerosis;

/**
 * The guest path: resolve-or-create the {@see CentralUser}, create the
 * {@see SocialAccount}, then delegate to {@see LoginUser}. Identity match is
 * `(provider, provider_id)` only, never email alone, which would let anyone
 * claiming a victim's address on a provider issuing unverified ones take over
 * their account. Linking by email needs both sides verified.
 *
 * @method static ?CentralUser run(SocialUserData $data)
 */
class LoginWithSocialAccount
{
    use AsAction;

    public function handle(SocialUserData $data): ?CentralUser
    {
        $centralUserClass = Numerosis::model(CentralUser::class);
        $socialAccountClass = Numerosis::model(SocialAccount::class);

        /** @var SocialAccount|null $existing */
        $existing = $socialAccountClass::query()
            ->where('provider', $data->provider)
            ->where('provider_id', $data->providerId)
            ->first();

        if ($existing !== null) {
            $this->refreshTokens($existing, $data);

            /** @var CentralUser $user */
            $user = $existing->user;

            LoginUser::run($user);

            return $user;
        }

        $user = $this->findVerifiedMatch($centralUserClass, $data);

        if ($user === null && $data->email !== null) {
            // An email exists locally but does not qualify for the
            // conditional link above, so refuse instead of creating a second
            // account for the same address.
            $hasLocalAccount = $centralUserClass::query()
                ->whereRaw('lower(email) = ?', [Str::lower($data->email)])
                ->exists();

            if ($hasLocalAccount) {
                return null;
            }
        }

        // One transaction for both writes. Split, a failure on the second left
        // a passwordless `CentralUser` with no credential, which the
        // `$hasLocalAccount` branch then refused on every retry.
        $user = DB::transaction(function () use ($centralUserClass, $socialAccountClass, $user, $data): CentralUser {
            $user ??= $this->createUser($centralUserClass, $data);

            $this->createSocialAccount($socialAccountClass, $user, $data);

            return $user;
        });

        LoginUser::run($user);

        return $user;
    }

    /**
     * @param  class-string<CentralUser>  $centralUserClass
     */
    private function findVerifiedMatch(string $centralUserClass, SocialUserData $data): ?CentralUser
    {
        if (! $data->emailVerified || $data->email === null) {
            return null;
        }

        /** @var CentralUser|null $match */
        $match = $centralUserClass::query()
            ->whereRaw('lower(email) = ?', [Str::lower($data->email)])
            ->whereNotNull('email_verified_at')
            ->first();

        return $match;
    }

    /**
     * @param  class-string<CentralUser>  $centralUserClass
     */
    private function createUser(string $centralUserClass, SocialUserData $data): CentralUser
    {
        return $centralUserClass::create([
            'name' => $data->name ?? $data->providerId,
            'email' => $data->email,
            'password' => null,
            'email_verified_at' => $data->emailVerified ? now() : null,
        ]);
    }

    /**
     * @param  class-string<SocialAccount>  $socialAccountClass
     */
    private function createSocialAccount(string $socialAccountClass, CentralUser $user, SocialUserData $data): void
    {
        $socialAccount = $socialAccountClass::create([
            'user_id' => $user->getKey(),
            'provider' => $data->provider,
            'provider_id' => $data->providerId,
            'name' => $data->name,
            'email' => $data->email,
            'avatar_url' => $data->avatarUrl,
            'token' => $data->token,
            'refresh_token' => $data->refreshToken,
            'token_expires_at' => $data->expiresAt,
        ]);

        event(new SocialAccountLinked($socialAccount, $user->global_id, $data->provider->value));
    }

    private function refreshTokens(SocialAccount $account, SocialUserData $data): void
    {
        $account->update([
            'name' => $data->name,
            'email' => $data->email,
            'avatar_url' => $data->avatarUrl,
            'token' => $data->token,
            'refresh_token' => $data->refreshToken,
            'token_expires_at' => $data->expiresAt,
        ]);
    }
}
