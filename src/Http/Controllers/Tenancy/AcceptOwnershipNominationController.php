<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Controllers\Tenancy;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Nvade\Numerosis\Actions\Tenancy\AcceptOwnershipNomination;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Exceptions\ShowsMessageToUser;
use Nvade\Numerosis\Http\Controllers\Controller;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\OwnershipNomination;
use Nvade\Numerosis\Routing\RouteNames;

class AcceptOwnershipNominationController extends Controller
{
    public function __invoke(OwnershipNomination $nomination): RedirectResponse
    {
        $user = Auth::guard(Context::Central->guard())->user();

        // `auth:web` guarantees this request is authenticated as a central
        // user; the guard clause is for PHPStan, never a real branch.
        abort_unless($user instanceof CentralUser, 403);

        try {
            $tenant = AcceptOwnershipNomination::run($nomination, $user);
        } catch (ShowsMessageToUser $e) {
            return to_route(RouteNames::tenantsMine())->with('status', $e->getMessage());
        }

        $domain = $tenant->primaryDomain();

        if ($domain === null) {
            return to_route(RouteNames::tenantsMine())->with('status', __('You are now the owner of :tenant.', ['tenant' => $tenant->name]));
        }

        return redirect()->to($domain->url);
    }
}
