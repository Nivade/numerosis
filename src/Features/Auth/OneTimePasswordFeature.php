<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Features\Auth;

use Nvade\Numerosis\Contracts\NamedFeature;
use Nvade\Numerosis\Features\Concerns\IsNamedFeature;

/**
 * Passwordless email OTP, layered on Fortify and off by default. When enabled,
 * {@see \Nvade\Numerosis\Actions\Auth\RedirectIfOneTimePasswordAuthenticatable}
 * intercepts `authenticateThrough()`'s pipeline before `AttemptToAuthenticate`,
 * sending a code and redirecting to the challenge screen in place of the
 * password check.
 */
class OneTimePasswordFeature implements NamedFeature
{
    use IsNamedFeature;

    public const NAME = 'one_time_password';

    /**
     * The named rate limiter guarding the challenge's verify leg, registered
     * in `NumerosisServiceProvider::registerAuthRateLimiters()` and applied
     * in {@see \Nvade\Numerosis\Numerosis::routes()}, which explains
     * why `fortify.limiters.login` is deliberately not used.
     */
    public const LIMITER = 'numerosis-one-time-password';
}
