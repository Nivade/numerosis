<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Controllers\Auth\Social;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Nvade\Numerosis\Events\Auth\SocialAccountUnlinked;
use Nvade\Numerosis\Http\Controllers\Controller;
use Nvade\Numerosis\Models\Central\SocialAccount;

class DestroySocialAccountController extends Controller
{
    public function __invoke(SocialAccount $socialAccount): RedirectResponse
    {
        Gate::authorize('delete', $socialAccount);

        $globalUserId = $socialAccount->user->global_id;
        $provider = $socialAccount->provider->value;

        $socialAccount->delete();

        event(new SocialAccountUnlinked($globalUserId, $provider));

        return redirect()->route('settings.connected-accounts')->with(
            'status',
            __(':provider disconnected.', ['provider' => $socialAccount->provider->label()]),
        );
    }
}
