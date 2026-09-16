<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Controllers\Tenancy;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Nvade\Numerosis\Exceptions\ShowsMessageToUser;
use Nvade\Numerosis\Http\Controllers\Controller;
use Nvade\Numerosis\Models\Central\OwnershipNomination;
use Nvade\Numerosis\Routing\RouteNames;

class ShowOwnershipNominationController extends Controller
{
    public function __invoke(OwnershipNomination $nomination): View|RedirectResponse
    {
        try {
            $nomination->assertClaimable();
        } catch (ShowsMessageToUser $e) {
            return to_route(RouteNames::tenantsMine())->with('status', $e->getMessage());
        }

        return view('numerosis::tenancy.ownership-nomination', ['nomination' => $nomination]);
    }
}
