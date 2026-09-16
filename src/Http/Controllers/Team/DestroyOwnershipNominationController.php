<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Controllers\Team;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Nvade\Numerosis\Http\Controllers\Controller;
use Nvade\Numerosis\Models\Central\OwnershipNomination;

class DestroyOwnershipNominationController extends Controller
{
    public function __invoke(OwnershipNomination $nomination): RedirectResponse
    {
        Gate::authorize('delete', $nomination);

        $nomination->delete();

        return back()->with('status', __('Ownership transfer revoked.'));
    }
}
