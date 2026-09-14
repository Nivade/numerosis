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
use Nvade\Numerosis\Enums\SessionKey;
use Nvade\Numerosis\Http\Controllers\Controller;
use Nvade\Numerosis\Models\User;
use Spatie\OneTimePasswords\Rules\OneTimePasswordRule;

/**
 * The `OneTimePasswordFeature` challenge screen. The candidate
 * `RedirectIfOneTimePasswordAuthenticatable` identified is re-resolved by the
 * address stashed in the session and from nowhere else: reaching this endpoint
 * proves nothing about which step ran before it, so a request-supplied address
 * would let a caller name any victim and skip the send step entirely.
 *
 * @see \Laravel\Fortify\Http\Controllers\TwoFactorAuthenticatedSessionController
 */
class OneTimePasswordChallengeController extends Controller
{
    public function create(Request $request): View
    {
        if (! $request->session()->has(SessionKey::LoginEmail->value)) {
            throw new HttpResponseException(to_route('login'));
        }

        return view('numerosis::auth.one-time-password-challenge');
    }

    public function store(Request $request, ResolvesLoginCandidate $resolver, AuthenticatesLoginCandidate $authenticator): mixed
    {
        $email = $request->session()->get(SessionKey::LoginEmail->value);

        if (! is_string($email)) {
            throw new HttpResponseException(to_route('login'));
        }

        $user = $resolver->find($email);

        // A session naming an address with no account fails exactly as a wrong
        // code does. A distinguishable outcome would re-open here the
        // account-existence oracle /login closes.
        if (! $user instanceof User) {
            throw ValidationException::withMessages([
                'code' => [trans('auth.failed')],
            ]);
        }

        $request->validate([
            'code' => ['required', 'string', new OneTimePasswordRule($user)],
        ]);

        $remember = (bool) $request->session()->pull(SessionKey::LoginRemember->value, false);
        $request->session()->forget(SessionKey::LoginEmail->value);

        $authenticator->authenticate($user, $remember);

        return resolve(LoginResponse::class);
    }
}
