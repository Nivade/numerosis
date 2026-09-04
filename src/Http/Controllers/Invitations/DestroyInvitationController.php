<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Controllers\Invitations;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Nvade\Numerosis\Http\Controllers\Controller;
use Nvade\Numerosis\Models\Central\Invitation;

class DestroyInvitationController extends Controller
{
    public function __invoke(Invitation $invitation): RedirectResponse
    {
        Gate::authorize('delete', $invitation);

        $invitation->delete();

        // `back()`, not the named route: path mode prefixes the tenant group
        // `{tenant}`, and with no URL default for that parameter
        // `route('team.invitations.index')` throws UrlGenerationException.
        return back()->with('status', __('Invitation revoked.'));
    }
}
