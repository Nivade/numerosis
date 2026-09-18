<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Nvade\Numerosis\Actions\Admin\EndImpersonation;
use Nvade\Numerosis\Actions\Queries\GetCurrentImpersonation;
use Nvade\Numerosis\Enums\Tenancy\ImpersonationEndReason;
use Nvade\Numerosis\Http\Controllers\Controller;
use Nvade\Numerosis\Models\Central\ImpersonationSession;
use Nvade\Numerosis\Routing\RouteUrls;

class EndImpersonationController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        $session = GetCurrentImpersonation::run();

        abort_unless($session instanceof ImpersonationSession, 403);

        EndImpersonation::run($session, ImpersonationEndReason::Exit);

        // Built instead of routed: this redirect leaves the tenant host for
        // the central one, and in path mode `route()` on a tenant-group name
        // throws for want of a `{tenant}` parameter.
        return redirect()->away(RouteUrls::staffTenantDetail($session->tenant_id));
    }
}
