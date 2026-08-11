<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Concerns\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Per-identifier-and-IP throttling for a login component.
 *
 * Throttle code *verification*, not just code sending. Sending is already
 * limited upstream; without this, a six-digit code is guessable at request
 * speed.
 */
trait ThrottlesLoginAttempts
{
    /** The value the limiter keys on alongside the request IP. */
    abstract protected function throttleIdentifier(): string;

    /**
     * Failed attempts allowed before the identifier is locked out.
     */
    protected function maxThrottleAttempts(): int
    {
        return 5;
    }

    /**
     * Ensure the authentication request is not rate limited.
     *
     * @throws ValidationException
     */
    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), $this->maxThrottleAttempts())) {
            return;
        }

        Event::dispatch(new Lockout(request()));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => __('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Record a failed attempt against the limiter.
     *
     * Named to avoid `hitRateLimiter()`/`clearRateLimiter()`, which Filament's
     * own login pages already declare with different signatures — a trait
     * reusing either name silently overrides theirs rather than colliding.
     */
    protected function hitLoginThrottle(): void
    {
        RateLimiter::hit($this->throttleKey());
    }

    /**
     * Forget the failed attempts for this identifier after a success.
     */
    protected function clearLoginThrottle(): void
    {
        RateLimiter::clear($this->throttleKey());
    }

    protected function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->throttleIdentifier()).'|'.request()->ip());
    }
}
