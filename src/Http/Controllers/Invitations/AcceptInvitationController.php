<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Controllers\Invitations;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Actions\Invitations\AcceptInvitation;
use Nvade\Numerosis\Exceptions\ShowsMessageToUser;
use Nvade\Numerosis\Http\Controllers\Controller;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Invitation;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Support\Numerosis;
use Nvade\Numerosis\Support\Routes\RouteNames;

class AcceptInvitationController extends Controller
{
    public function __invoke(Invitation $invitation): RedirectResponse
    {
        /** @var CentralUser $user */
        $user = Auth::guard(Config::string('numerosis.auth.guards.central'))->user();

        try {
            $invitation = AcceptInvitation::run($invitation, $user);
        } catch (ShowsMessageToUser $e) {
            // `auth:web` guarantees this request is authenticated, so `login`
            // would bounce off Fortify's `guest` middleware and lose the flash.
            return redirect()->route(RouteNames::tenantsMine())->with('status', $e->getMessage());
        }

        session()->forget('pending_invitation');

        $tenantClass = Numerosis::model(Tenant::class);
        $tenant = $tenantClass::find($invitation->tenant_id);
        $domain = $tenant?->primaryDomain();

        if ($domain === null) {
            return redirect()->route(RouteNames::tenantsMine());
        }

        return redirect()->to($domain->url);
    }
}
