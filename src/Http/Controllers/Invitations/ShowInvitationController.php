<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Controllers\Invitations;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Nvade\Numerosis\Enums\SessionKey;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Exceptions\ShowsMessageToUser;
use Nvade\Numerosis\Http\Controllers\Controller;
use Nvade\Numerosis\Models\Central\Invitation;
use Nvade\Numerosis\Routing\RouteNames;

class ShowInvitationController extends Controller
{
    public function __invoke(Invitation $invitation): View|RedirectResponse
    {
        $authenticated = Auth::guard(Context::Central->guard())->check();

        try {
            $invitation->assertClaimable();
        } catch (ShowsMessageToUser $e) {
            // Fortify's `login` carries `guest`, which bounces an
            // authenticated visitor to `home` and drops the flash with it,
            // hiding the message from exactly the people who are signed in.
            return $authenticated
                ? redirect()->route(RouteNames::tenantsMine())->with('status', $e->getMessage())
                : redirect()->route('login')->with('status', $e->getMessage());
        }

        if (! $authenticated) {
            // The email is stashed alongside the key so the register view can
            // prefill it without querying from Blade.
            session([SessionKey::PendingInvitation->value => [
                'ulid' => $invitation->getRouteKey(),
                'email' => $invitation->email,
            ]]);

            return redirect()->guest(route('login'));
        }

        return view('numerosis::invitations.show', ['invitation' => $invitation]);
    }
}
