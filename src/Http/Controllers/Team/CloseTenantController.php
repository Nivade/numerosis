<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Controllers\Team;

use Illuminate\Http\RedirectResponse;
use Nvade\Numerosis\Actions\Tenancy\CloseTenant;
use Nvade\Numerosis\Exceptions\Tenancy\TenantClosureBlocked;
use Nvade\Numerosis\Http\Controllers\Controller;
use Nvade\Numerosis\Http\Requests\Team\CloseTenantRequest;
use Nvade\Numerosis\Models\Central\Tenant;

class CloseTenantController extends Controller
{
    public function __invoke(CloseTenantRequest $request): RedirectResponse
    {
        $tenant = tenant();

        abort_unless($tenant instanceof Tenant, 403);

        try {
            CloseTenant::run($tenant, $request->acknowledgesOutstandingBalance());
        } catch (TenantClosureBlocked $e) {
            return back()->withErrors(['name' => $e->getMessage()], 'closeTenant');
        }

        // `back()`, never the named route: path mode prefixes the tenant group
        // `{tenant}` and nothing registers a URL default for that parameter.
        // `tenancy.subscription` turns that into the closure notice.
        return back();
    }
}
