<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Features\Auth;

use Nvade\Numerosis\Contracts\NamedFeature;
use Nvade\Numerosis\Models\User;
use Nvade\Numerosis\Notifications\Auth\VerifyEmail;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\URL;

/**
 * Moved here verbatim from AppServiceProvider::boot() — registered
 * unconditionally in config('numerosis.features'), gating no routes.
 *
 * This is deliberately NOT a real on/off switch. Nvade\Numerosis\Models\User (the shared
 * abstract base for both CentralUser and Tenant\User) implements
 * MustVerifyEmail unconditionally, so the interface demands a working
 * verification route regardless of this class's presence — removing it here
 * would leave VerifyEmail::createUrlUsing() unset while Laravel still tries
 * to build a signed verification URL, throwing rather than degrading.
 * Gating verification.verify/verification.notice properly means deciding
 * what a verification-free deployment *is* (drop MustVerifyEmail per-model,
 * or gate only the notification send, or leave it out of the feature system
 * entirely) — a product decision, not a mechanical toggle. See Phase 5.1 in
 * .claude/plans/opt-in-feature-classes.md.
 */
class EmailVerificationFeature implements NamedFeature
{
    public const NAME = 'email_verification';

    public static function featureName(): string
    {
        return self::NAME;
    }

    public function bootstrap(): void
    {
        VerifyEmail::createUrlUsing(
            callback: fn (User $notifiable) => URL::temporarySignedRoute('verification.verify',
                expiration: Date::now()->addMinutes(Config::integer('auth.verification.expire', 60)),
                parameters: [
                    'id' => $notifiable->getGlobalIdentifierKey(),
                    'hash' => sha1($notifiable->getEmailForVerification()),
                ])
        );
    }
}
