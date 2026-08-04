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
 * Originally duplicated byte-identically across two login surfaces — the
 * password-based `Nvade\Numerosis\Livewire\Auth\Login` (dead code, deleted 2026-08-04;
 * see .claude/rules/auth-login.md) and `Nvade\Numerosis\Livewire\Auth\PasswordlessLogin`
 * — differing only in a `(string)` cast. That is the same shape as the
 * drift .claude/rules/auth-login.md records twice over — turnstile added to
 * one login surface and not the other, then the OTP check itself — and the
 * reason it matters here specifically is that `PasswordlessLogin` needed
 * this to *verify* codes, not merely to send them: the parent
 * `OneTimePasswordComponent::rateLimitHit()` throttles `sendCode()` alone,
 * leaving a six-digit code guessable at request speed. A helper that exists
 * but has no caller reads as protection and is not — check for a caller
 * before assuming the limit is live.
 */
trait ThrottlesLoginAttempts
{
    /**
     * The value the limiter keys on alongside the request IP.
     *
     * Kept abstract rather than reading `$this->email` directly because the
     * only remaining consumer, `PasswordlessLogin`, inherits a `?string`
     * property from the package's own component — a trait cannot paper over
     * a nullable/non-nullable mismatch without hiding it, and the previous
     * (now-deleted) consumer typed it `string`, which is exactly the
     * mismatch this abstraction existed to isolate.
     */
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
     * Deliberately *not* named `hitRateLimiter()`/`clearRateLimiter()`:
     * `DanHarrin\LivewireRateLimiting\WithRateLimiting` declares both names
     * with different signatures, and any Filament page composing that
     * trait — the deleted password-based `Login` page was one, see
     * .claude/rules/auth-login.md — would have that limiter silently
     * overridden by reusing either name. General rule, not specific to a
     * consumer that no longer exists: grep a page's parent chain for a name
     * before adding a method to a concern it composes.
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

    /**
     * Get the authentication rate limiting throttle key.
     */
    protected function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->throttleIdentifier()).'|'.request()->ip());
    }
}
