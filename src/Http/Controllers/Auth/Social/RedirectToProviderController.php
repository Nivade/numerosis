<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Controllers\Auth\Social;

use Laravel\Socialite\Facades\Socialite;
use Nvade\Numerosis\Enums\Auth\SocialProvider;
use Nvade\Numerosis\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Never `->stateless()`: these are web routes, and the `state` parameter is
 * the CSRF defence for the callback.
 */
class RedirectToProviderController extends Controller
{
    public function __invoke(SocialProvider $provider): RedirectResponse
    {
        return Socialite::driver($provider->value)->redirect();
    }
}
