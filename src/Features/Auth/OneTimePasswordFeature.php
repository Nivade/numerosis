<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Features\Auth;

use Nvade\Numerosis\Contracts\NamedFeature;
use Nvade\Numerosis\Support\Features;
use RuntimeException;
use Spatie\OneTimePasswords\Models\Concerns\HasOneTimePasswords;

/**
 * Passwordless email OTP, layered on Fortify instead of replacing it. When
 * enabled, `Nvade\Numerosis\Actions\Auth\RedirectIfOneTimePasswordAuthenticatable`
 * intercepts Fortify's `authenticateThrough()` pipeline before
 * `AttemptToAuthenticate`: it identifies the candidate by email, sends a
 * code and redirects to the challenge screen instead of checking a password.
 *
 * Off by default, since Fortify's password login is the default login
 * method once this feature is not enabled.
 */
class OneTimePasswordFeature implements NamedFeature
{
    public const NAME = 'one_time_password';

    /**
     * The named rate limiter guarding the challenge's verify leg, registered
     * in `NumerosisServiceProvider::registerAuthRateLimiters()` and applied
     * in {@see \Nvade\Numerosis\Support\Numerosis::routes()}. Deliberately
     * not `fortify.limiters.login` — see that method.
     */
    public const LIMITER = 'numerosis-one-time-password';

    public static function featureName(): string
    {
        return self::NAME;
    }

    /**
     * The single `trait_exists()` seam for `spatie/laravel-one-time-passwords`,
     * a `suggest`, not a `require`. Every call site asks this rather than
     * probing the package itself: the route registration in {@see
     * \Nvade\Numerosis\Support\Numerosis::routes()}, the pipeline step in
     * `NumerosisServiceProvider::registerFortify()` and {@see self::bootstrap()}
     * are the three.
     *
     * Without it, `User` composes the empty
     * {@see \Nvade\Numerosis\Support\Compat\HasOneTimePasswordsIfInstalled}
     * and `sendOneTimePassword()` does not exist, so the challenge route
     * would fatal on `new OneTimePasswordRule` rather than degrade.
     */
    public static function available(): bool
    {
        return Features::enabled(self::NAME) && trait_exists(HasOneTimePasswords::class);
    }

    /**
     * Only runs when the feature is listed in `numerosis.features`, which
     * makes this the one place a host learns it asked for OTP without
     * installing the package — loudly, at boot, rather than as a
     * "class not found" on somebody's login attempt. {@see self::available()}
     * is then free to be a silent gate everywhere else.
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
