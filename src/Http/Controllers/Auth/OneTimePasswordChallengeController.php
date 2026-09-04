<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Controllers\Auth;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Laravel\Fortify\Contracts\LoginResponse;
use Nvade\Numerosis\Contracts\Auth\AuthenticatesLoginCandidate;
use Nvade\Numerosis\Contracts\Auth\ResolvesLoginCandidate;
use Nvade\Numerosis\Http\Controllers\Controller;
use Nvade\Numerosis\Models\User;
use Spatie\OneTimePasswords\Rules\OneTimePasswordRule;

/**
 * Mirrors Fortify's own `TwoFactorAuthenticatedSessionController`, for the
 * `OneTimePasswordFeature` challenge screen. The candidate identified by
 * `RedirectIfOneTimePasswordAuthenticatable` is re-resolved here by the
 * email stashed in the session rather than a guard-scoped ID, so this
 * controller stays agnostic to which guard (central or tenant) is currently
 * being logged into — same reasoning `ResolvesLoginCandidate` already
 * encodes for the deleted `PasswordlessLogin` component.
 *
 * The address is read from the session and from nowhere else. Reaching
 * this endpoint proves nothing about which step ran before it, so accepting
 * a request-supplied address here would let a caller name any victim and
 * skip the send step entirely. `OneTimePasswordRule` would still refuse,
 * but a control that only holds because a second one happens to sit behind
 * it is the drift that produced that bug in the first place.
 * `OneTimePasswordLoginTest` mutation-tests this specific line.
 *
 * No `#[\SensitiveParameter]` appears here on purpose: the submitted code
 * never becomes a named parameter on this side — it stays inside `$request`
 * and the validator, which Sentry scrubs by key rather than by attribute.
 */
class OneTimePasswordChallengeController extends Controller
{
    public function create(Request $request): View
    {
        if (! $request->session()->has('login.email')) {
            throw new HttpResponseException(redirect()->route('login'));
        }

        return view('numerosis::auth.one-time-password-challenge');
    }

    public function store(Request $request, ResolvesLoginCandidate $resolver, AuthenticatesLoginCandidate $authenticator): mixed
    {
        $email = $request->session()->get('login.email');

        if (! is_string($email)) {
            throw new HttpResponseException(redirect()->route('login'));
        }

        $user = $resolver->find($email);

        // A session naming an address with no account fails exactly as a
        // wrong code does, rather than redirecting: a distinguishable outcome
        // here would re-open on this endpoint the account-existence oracle
        // that `RedirectIfOneTimePasswordAuthenticatable` closes on /login.
        if (! $user instanceof User) {
            throw ValidationException::withMessages([
                'code' => [trans('auth.failed')],
            ]);
        }

        $request->validate([
            'code' => ['required', 'string', new OneTimePasswordRule($user)],
        ]);

        $remember = (bool) $request->session()->pull('login.remember', false);
        $request->session()->forget('login.email');

        $authenticator->authenticate($user, $remember);

        return app(LoginResponse::class);
    }
}
