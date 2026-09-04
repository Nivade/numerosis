<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Features\Auth;

use Nvade\Numerosis\Contracts\NamedFeature;
use Nvade\Numerosis\Support\Features;
use RuntimeException;
use Spatie\OneTimePasswords\Models\Concerns\HasOneTimePasswords;

/**
 * Passwordless email OTP, layered on Fortify and off by default. When enabled,
 * {@see \Nvade\Numerosis\Actions\Auth\RedirectIfOneTimePasswordAuthenticatable}
 * intercepts `authenticateThrough()`'s pipeline before `AttemptToAuthenticate`,
 * sending a code and redirecting to the challenge screen in place of the
 * password check.
 */
class OneTimePasswordFeature implements NamedFeature
{
    public const NAME = 'one_time_password';

    /**
     * The named rate limiter guarding the challenge's verify leg, registered
     * in `NumerosisServiceProvider::registerAuthRateLimiters()` and applied
     * in {@see \Nvade\Numerosis\Support\Numerosis::routes()}, which explains
     * why `fortify.limiters.login` is deliberately not used.
     */
    public const LIMITER = 'numerosis-one-time-password';

    public static function featureName(): string
    {
        return self::NAME;
    }

    /**
     * The single `trait_exists()` seam for `spatie/laravel-one-time-passwords`,
     * a `composer.json` `suggest`. Nothing else probes that package: without it
     * `User` composes an empty compat trait, `sendOneTimePassword()` does not
     * exist, and the challenge route fatals on `new OneTimePasswordRule`.
     *
     * @see \Nvade\Numerosis\Support\Compat\HasOneTimePasswordsIfInstalled
     */
    public static function available(): bool
    {
        return Features::enabled(self::NAME) && trait_exists(HasOneTimePasswords::class);
    }

    /**
     * Only runs when the feature is listed in `numerosis.features`, which
     * makes this the one place a host learns at boot that it asked for OTP
     * without installing the package, before somebody's login attempt hits a
     * "class not found". {@see self::available()} is then free to be a silent
     * gate everywhere else.
     */
    public function bootstrap(): void
    {
        throw_unless(
            self::available(),
            RuntimeException::class,
            'OneTimePasswordFeature is enabled but spatie/laravel-one-time-passwords is not installed. Require it, or remove the feature from config("numerosis.features").'
        );
    }
}
