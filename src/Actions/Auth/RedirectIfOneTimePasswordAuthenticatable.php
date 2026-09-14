<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Auth;

use Illuminate\Http\Request;
use Laravel\Fortify\Fortify;
use Nvade\Numerosis\Contracts\Auth\ResolvesLoginCandidate;
use Nvade\Numerosis\Enums\SessionKey;
use Nvade\Numerosis\Models\User;

/**
 * Replaces the password step of Fortify's pipeline when
 * `OneTimePasswordFeature::available()`: it identifies the candidate, sends
 * the code and redirects to the challenge screen, so it never calls `$next()`.
 * The response is identical whether or not the address belongs to a user,
 * because there is no password here to make a wrong one indistinguishable.
 *
 * @see \Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable
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
            SessionKey::LoginEmail->value => $email,
            SessionKey::LoginRemember->value => $request->boolean('remember'),
        ]);

        return $request->wantsJson()
            ? response()->json(['one_time_password' => true])
            : to_route('one-time-password.login');
    }
}
