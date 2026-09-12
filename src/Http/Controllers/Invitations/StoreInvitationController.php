<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Controllers\Invitations;

use Illuminate\Http\RedirectResponse;
use Nvade\Numerosis\Actions\Invitations\SendInvitation;
use Nvade\Numerosis\Http\Controllers\Controller;
use Nvade\Numerosis\Http\Requests\Invitations\StoreInvitationRequest;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\User;

class StoreInvitationController extends Controller
{
    public function __invoke(StoreInvitationRequest $request): RedirectResponse
    {
        /** @var Tenant $currentTenant */
        $currentTenant = tenant();

        $user = $request->user();

        abort_unless($user instanceof User, 403);

        SendInvitation::run($currentTenant, $request->toInvitationData(), $user);

        // `back()`, never the named route: path mode prefixes the tenant group
        // `{tenant}`, and with no URL default for that parameter
        // `route('team.invitations.index')` throws UrlGenerationException.
        return back()->with('status', __('Invitation sent.'));
    }
}
