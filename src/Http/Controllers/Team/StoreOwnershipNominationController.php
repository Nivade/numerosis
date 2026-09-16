<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Controllers\Team;

use Illuminate\Http\RedirectResponse;
use Nvade\Numerosis\Actions\Tenancy\NominateTenantOwner;
use Nvade\Numerosis\Exceptions\Tenancy\OwnershipTransferBlocked;
use Nvade\Numerosis\Http\Controllers\Controller;
use Nvade\Numerosis\Http\Requests\Team\NominateOwnerRequest;
use Nvade\Numerosis\Models\Central\Membership;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\User;

class StoreOwnershipNominationController extends Controller
{
    public function __invoke(NominateOwnerRequest $request): RedirectResponse
    {
        $tenant = tenant();
        $target = $request->target();
        $user = $request->user();

        abort_unless($tenant instanceof Tenant && $target instanceof Membership, 403);

        try {
            NominateTenantOwner::run($tenant, $target, $user instanceof User ? $user->global_id : null);
        } catch (OwnershipTransferBlocked $e) {
            return back()->withErrors(['membership' => $e->getMessage()], 'ownershipTransfer');
        }

        // `back()`, never the named route: path mode prefixes the tenant group
        // `{tenant}` and nothing registers a URL default for that parameter.
        return back()->with('status', __('Ownership transfer sent. It takes effect once they accept.'));
    }
}
