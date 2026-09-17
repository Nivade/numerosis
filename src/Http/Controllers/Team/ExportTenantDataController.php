<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Controllers\Team;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Nvade\Numerosis\Actions\Queries\FindMembershipForUser;
use Nvade\Numerosis\Actions\Queries\GetAuthenticatedUser;
use Nvade\Numerosis\Actions\Tenancy\RequestTenantDataExport;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Exceptions\ShowsMessageToUser;
use Nvade\Numerosis\Http\Controllers\Controller;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Membership;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\User;
use Nvade\Numerosis\Numerosis;

class ExportTenantDataController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $tenant = tenant();
        $user = GetAuthenticatedUser::run(Context::Tenant->guard());

        abort_unless($tenant instanceof Tenant && $user instanceof User, 403);

        $membership = FindMembershipForUser::run($tenant->id, $user->global_id);

        abort_unless($membership instanceof Membership && Gate::allows('manageClosure', $membership), 403);

        // The link goes to the central account, not to the tenant-side twin:
        // that twin has no mailbox of its own.
        $owner = Numerosis::model(CentralUser::class)::query()->where('global_id', $user->global_id)->first();

        abort_unless($owner instanceof CentralUser, 403);

        try {
            RequestTenantDataExport::run($tenant, $owner);
        } catch (ShowsMessageToUser $refusal) {
            return back()->with('status', $refusal->getMessage());
        }

        return back()->with('status', __('We are preparing an export of this workspace. You will get a download link by email.'));
    }
}
