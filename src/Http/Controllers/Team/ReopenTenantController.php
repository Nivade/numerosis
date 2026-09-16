<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Controllers\Team;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Nvade\Numerosis\Actions\Queries\FindMembershipForUser;
use Nvade\Numerosis\Actions\Tenancy\ReopenTenant;
use Nvade\Numerosis\Http\Controllers\Controller;
use Nvade\Numerosis\Models\Central\Membership;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\User;
use Nvade\Numerosis\Routing\RouteNames;

class ReopenTenantController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $tenant = tenant();
        $user = $request->user();

        abort_unless($tenant instanceof Tenant, 403);

        $membership = FindMembershipForUser::run(
            (string) $tenant->getTenantKey(),
            $user instanceof User ? $user->global_id : null,
        );

        abort_unless($membership instanceof Membership && Gate::allows('manageClosure', $membership), 403);

        if (ReopenTenant::run($tenant)) {
            return back()->with('status', __('Your workspace is open again and your subscription has resumed.'));
        }

        // The subscription lapsed while the workspace was closed, and starting
        // a new one is a central-domain screen.
        return redirect()->to(route(RouteNames::tenantsMine()))
            ->with('status', __('Your workspace is open again. Start a subscription to keep it.'));
    }
}
