<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Controllers\Invitations;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Exceptions\Invitations\InvitationAlreadyAccepted;
use Nvade\Numerosis\Exceptions\Invitations\InvitationExpired;
use Nvade\Numerosis\Exceptions\ShowsMessageToUser;
use Nvade\Numerosis\Http\Controllers\Controller;
use Nvade\Numerosis\Models\Central\Invitation;
use Nvade\Numerosis\Support\Routes\RouteNames;

class ShowInvitationController extends Controller
{
    public function __invoke(Invitation $invitation): View|RedirectResponse
    {
        $authenticated = Auth::guard(Config::string('numerosis.auth.guards.central'))->check();

        try {
            throw_if($invitation->isAccepted(), InvitationAlreadyAccepted::class, 'This invitation has already been accepted.');
            throw_if($invitation->isExpired(), InvitationExpired::class, 'This invitation has expired.');
        } catch (ShowsMessageToUser $e) {
            // Fortify's `login` carries `guest`, which bounces an
            // authenticated visitor to `home` and drops the flash with it, so
            // the message would be invisible to exactly the people who are
            // signed in.
            return $authenticated
                ? redirect()->route(RouteNames::tenantsMine())->with('status', $e->getMessage())
                : redirect()->route('login')->with('status', $e->getMessage());
        }

        if (! $authenticated) {
            // The email is stashed alongside the key so the register view can
            // prefill it without querying from Blade.
            session(['pending_invitation' => [
                'ulid' => $invitation->getRouteKey(),
                'email' => $invitation->email,
            ]]);

            return redirect()->guest(route('login'));
        }

        return view('numerosis::invitations.show', ['invitation' => $invitation]);
    }
}
