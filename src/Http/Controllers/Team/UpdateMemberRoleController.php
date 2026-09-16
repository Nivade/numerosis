<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Controllers\Team;

use Illuminate\Http\RedirectResponse;
use Nvade\Numerosis\Actions\Tenancy\ChangeMemberRole;
use Nvade\Numerosis\Exceptions\Tenancy\LastAdminRequired;
use Nvade\Numerosis\Exceptions\Tenancy\OwnerMembershipImmutable;
use Nvade\Numerosis\Http\Controllers\Controller;
use Nvade\Numerosis\Http\Requests\Team\UpdateMemberRoleRequest;
use Nvade\Numerosis\Models\Central\Membership;

class UpdateMemberRoleController extends Controller
{
    public function __invoke(UpdateMemberRoleRequest $request, Membership $membership): RedirectResponse
    {
        try {
            ChangeMemberRole::run($membership, $request->role());
        } catch (LastAdminRequired|OwnerMembershipImmutable $e) {
            return back()->withErrors(['role' => $e->getMessage()], 'memberRole');
        }

        // `back()`, never the named route: path mode prefixes the tenant group
        // `{tenant}`, and with no URL default for that parameter
        // `route('team.index')` throws UrlGenerationException.
        return back()->with('status', __('Role updated.'));
    }
}
