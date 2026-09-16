<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Controllers\Team;

use Illuminate\Http\RedirectResponse;
use Nvade\Numerosis\Actions\Tenancy\SetTenantTwoFactorRequirement;
use Nvade\Numerosis\Http\Controllers\Controller;
use Nvade\Numerosis\Http\Requests\Team\UpdateTwoFactorRequirementRequest;
use Nvade\Numerosis\Models\Central\Tenant;

class UpdateTwoFactorRequirementController extends Controller
{
    public function __invoke(UpdateTwoFactorRequirementRequest $request): RedirectResponse
    {
        $tenant = tenant();

        abort_unless($tenant instanceof Tenant, 403);

        SetTenantTwoFactorRequirement::run($tenant, $request->requirement());

        // `back()`, never the named route: path mode prefixes the tenant group
        // `{tenant}` and nothing registers a URL default for that parameter.
        return back();
    }
}
