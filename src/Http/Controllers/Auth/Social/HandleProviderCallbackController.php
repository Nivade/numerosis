<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Controllers\Auth\Social;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Contracts\LoginResponse;
use Nvade\Numerosis\Actions\Auth\Social\LinkSocialAccount;
use Nvade\Numerosis\Actions\Auth\Social\LoginWithSocialAccount;
use Nvade\Numerosis\Actions\Auth\Social\ResolveSocialUser;
use Nvade\Numerosis\Enums\Auth\SocialProvider;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Exceptions\ShowsMessageToUser;
use Nvade\Numerosis\Http\Controllers\Controller;
use Nvade\Numerosis\Models\Central\CentralUser;
use Symfony\Component\HttpFoundation\Response;

/**
 * Branches on auth state, so an authed visitor connects and a guest logs in.
 * Stashing an "intent" in the session would be forgeable.
 *
 * The guest path finishes through Fortify's `LoginResponse`, exactly as a
 * password login does, so there is no second post-login redirect mechanism.
 */
class HandleProviderCallbackController extends Controller
{
    public function __invoke(SocialProvider $provider): RedirectResponse|Response
    {
        $data = ResolveSocialUser::run($provider);

        /** @var CentralUser|null $authed */
        $authed = Auth::guard(Context::Central->guard())->user();

        if ($authed instanceof CentralUser) {
            try {
                LinkSocialAccount::run($authed, $data);
            } catch (ShowsMessageToUser $e) {
                return redirect()->route('settings.connected-accounts')->with('status', $e->getMessage());
            }

            return redirect()->route('settings.connected-accounts')->with(
                'status',
                __(':provider connected.', ['provider' => $provider->label()]),
            );
        }

        $user = LoginWithSocialAccount::run($data);

        if ($user === null) {
            return redirect()->route('login')->with(
                'status',
                __('An account already exists for that email address. Sign in first, then connect this account from settings.'),
            );
        }

        return app(LoginResponse::class)->toResponse(request());
    }
}
