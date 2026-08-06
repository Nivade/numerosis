<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Testing;

use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Models\Central\Tenant;

/**
 * Exported from the package (rather than kept in the package's own
 * `tests/`, which `autoload-dev` never ships to a consumer) so a host's own
 * test suite — thin-app's Phase 7.5 tests — can reach it too. A copy is how
 * the two drift; see .claude/plans/package-extraction.md, Phase 6.6.
 */
trait InteractsWithTenantPanel
{
    /**
     * Enter a tenant panel the way an HTTP request would.
     *
     * Filament runs its own tenancy alongside stancl's and the two share no
     * state. `$tenant->run()` switches the database, cache, guard and
     * permission registrar — everything stancl owns — but Filament keeps its
     * current tenant in its own manager, and a tenant panel provider
     * declaring `->tenant(Tenant::class, 'id')` puts a `{tenant}` parameter
     * on every route in that panel, filled from `Filament::getTenant()`.
     *
     * A real request sets that through Filament's own middleware. A
     * `Livewire::test()` never issues a request, so it stays null and the
     * first route() call in a rendered sidebar throws
     * "Missing required parameter for [Route: filament.tenantAdmin...]" —
     * which reads like a routing bug and is not one.
     *
     * Setting only two of these three is the trap this helper removes.
     */
    protected function actingAsTenantPanelUser(Tenant $tenant, Authenticatable $user, string $panel = 'tenantAdmin'): void
    {
        $this->actingAs($user, Config::string('auth.defaults.guards.context.tenant'));

        Filament::setCurrentPanel(Filament::getPanel($panel));
        Filament::setTenant($tenant);
    }
}
