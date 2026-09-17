<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Controllers\Invitations;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Nvade\Numerosis\Actions\Invitations\AcceptInvitation;
use Nvade\Numerosis\Enums\SessionKey;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Exceptions\ShowsMessageToUser;
use Nvade\Numerosis\Http\Controllers\Controller;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Invitation;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Numerosis;
use Nvade\Numerosis\Routing\RouteNames;

class AcceptInvitationController extends Controller
{
    public function __invoke(Invitation $invitation): RedirectResponse
    {
        $user = Auth::guard(Context::Central->guard())->user();

        // `auth:web` guarantees this request is authenticated as a central
        // user; the guard clause is for PHPStan, never a real branch.
        abort_unless($user instanceof CentralUser, 403);

        try {
            $invitation = AcceptInvitation::run($invitation, $user);
        } catch (ShowsMessageToUser $e) {
            // `auth:web` guarantees this request is authenticated, so `login`
            // would bounce off Fortify's `guest` middleware and lose the flash.
            return to_route(RouteNames::tenantsMine())->with('status', $e->getMessage());
        }

        session()->forget(SessionKey::PendingInvitation->value);

        $tenantClass = Numerosis::model(Tenant::class);
        $tenant = $tenantClass::find($invitation->tenant_id);
        $domain = $tenant?->primaryDomain();

        if ($domain === null) {
            return to_route(RouteNames::tenantsMine());
        }

        return redirect()->to($domain->url);
    }
}
