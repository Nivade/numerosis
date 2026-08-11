<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Features\Auth;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\URL;
use Nvade\Numerosis\Contracts\NamedFeature;
use Nvade\Numerosis\Models\User;
use Nvade\Numerosis\Notifications\Auth\VerifyEmail;

/**
 * Builds the tenant-aware email-verification URL.
 *
 * Not a toggle, despite its place in `numerosis.features`: the package's
 * user models implement `MustVerifyEmail` unconditionally, so removing this
 * leaves Laravel building a verification URL with nothing to build it from.
 * Suppress the verification email itself if you do not want one.
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
                expiration: Date::now()->addMinutes(Config::integer('numerosis.auth.verification_expire', 60)),
                parameters: [
                    'id' => $notifiable->getGlobalIdentifierKey(),
                    'hash' => sha1($notifiable->getEmailForVerification()),
                ])
        );
    }
}
