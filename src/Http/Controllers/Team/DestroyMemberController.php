<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Controllers\Team;

use Illuminate\Auth\AuthManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Nvade\Numerosis\Actions\Tenancy\RemoveMember;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Exceptions\Tenancy\OwnerMembershipImmutable;
use Nvade\Numerosis\Http\Controllers\Controller;
use Nvade\Numerosis\Models\Central\Membership;
use Nvade\Numerosis\Models\User;
use Nvade\Numerosis\Routing\RouteNames;

class DestroyMemberController extends Controller
{
    public function __construct(private readonly AuthManager $auth) {}

    public function __invoke(Request $request, Membership $membership): RedirectResponse
    {
        Gate::authorize('delete', $membership);

        $user = $request->user();
        $isSelf = $user instanceof User && $membership->global_user_id === $user->global_id;

        try {
            RemoveMember::run($membership);
        } catch (OwnerMembershipImmutable $e) {
            return back()->withErrors(['member' => $e->getMessage()], 'teamMembers');
        }

        if ($isSelf) {
            // The tenant guard's session outlives the membership until the
            // next request, and the central one is what carries them home.
            $this->auth->guard(Context::Tenant->guard())->logout();

            return redirect()->to(route(RouteNames::tenantsMine()))->with('status', __('You have left the team.'));
        }

        // `back()`, never the named route: path mode prefixes the tenant group
        // `{tenant}`, and with no URL default for that parameter
        // `route('team.index')` throws UrlGenerationException.
        return back()->with('status', __('Member removed.'));
    }
}
