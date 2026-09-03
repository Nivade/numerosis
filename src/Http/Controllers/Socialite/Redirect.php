<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Controllers\Socialite;

use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Nvade\Numerosis\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\RedirectResponse;

class Redirect extends Controller
{
    public function __invoke(Request $request, string $provider): RedirectResponse|\Illuminate\Http\RedirectResponse
    {
        $invitation = $request->query('invitation');
        $tenant = $request->query('tenant');
        $returnUrl = $request->query('return_url');

        $sessionData = [];

        if (! empty($tenant)) {
            $sessionData['tenant'] = $tenant;
        }

        if (! empty($invitation)) {
            $sessionData['invitation'] = $invitation;
            $sessionData['intent'] = 'accept_invitation';
        }

        if (! empty($returnUrl)) {
            $sessionData['return_url'] = $returnUrl;
        }

        if (! empty($sessionData)) {
            $request->session()->put('socialite_context', $sessionData);
        }

        return Socialite::driver($provider)->redirect();
    }
}
