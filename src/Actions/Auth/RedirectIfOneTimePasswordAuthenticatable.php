<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Auth;

use Illuminate\Http\Request;
use Laravel\Fortify\Fortify;
use Nvade\Numerosis\Contracts\Auth\ResolvesLoginCandidate;
use Nvade\Numerosis\Models\User;

/**
 * Modelled on Fortify's own `Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable`,
 * but OTP replaces password authentication rather than layering on top of
 * it: `OneTimePasswordFeature` is mutually exclusive with the password step,
 * not an additional factor after it, so this step never calls `$next()`
 * once the feature is on — it identifies the candidate, sends the code and
 * redirects to the challenge screen itself. Inserted after
 * `CanonicalizeUsername` and before `AttemptToAuthenticate` in
 * `NumerosisServiceProvider::registerFortify()`'s pipeline, only when
 * `OneTimePasswordFeature::available()`.
 *
 * **The response is identical whether or not the address belongs to a user.**
 * There is no password to check here, so a per-outcome response would make
 * this endpoint an unauthenticated account-existence oracle for any address
 * an attacker cares to submit — a password login leaks nothing comparable,
 * because a wrong password and an unknown user both fail the same way.
 * `OneTimePasswordChallengeController::store()` holds the other half of that
 * bargain: a session pointing at an address with no account fails code
 * validation with the same generic message a wrong code gets.
 *
 * What this does *not* hide: an unauthenticated caller can make this package
 * send mail to any address it holds an account for, and
 * `one-time-passwords.only_one_active_one_time_password_per_user` means each
 * send invalidates the previous code, so a third party can keep a victim's
 * pending code from working. Both are inherent to passwordless email login;
 * the `login` rate limiter (`NumerosisServiceProvider::registerAuthRateLimiters()`,
 * keyed tenant + address + IP) is what bounds them.
 */
class RedirectIfOneTimePasswordAuthenticatable
{
    public function __construct(protected ResolvesLoginCandidate $resolver) {}

    public function handle(Request $request, callable $next): mixed
    {
        $email = (string) $request->string(Fortify::username());

        $user = $this->resolver->find($email);

        if ($user instanceof User) {
            $user->sendOneTimePassword();
        }

        $request->session()->put([
            'login.email' => $email,
            'login.remember' => $request->boolean('remember'),
        ]);

        return $request->wantsJson()
            ? response()->json(['one_time_password' => true])
            : redirect()->route('one-time-password.login');
    }
}
