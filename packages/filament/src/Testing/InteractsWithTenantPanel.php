<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\Testing;

use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Models\Central\Tenant;

/** Test helpers for driving a tenant Filament panel. */
trait InteractsWithTenantPanel
{
    /**
     * Enter a tenant panel the way an HTTP request would: authenticate on the
     * tenant guard, select the panel, and set Filament's own current tenant.
     *
     * All three are required. Filament runs its own tenancy alongside
     * stancl/tenancy and the two share no state, so switching tenant database
     * and guard still leaves Filament's tenant unset — and every route in a
     * tenant panel needs it. A real request sets it via Filament's
     * middleware; a Livewire test issues no request, and fails on the first
     * route generated in a rendered page with "Missing required parameter",
     * which reads like a routing bug and is not one.
     */
    protected function actingAsTenantPanelUser(Tenant $tenant, Authenticatable $user, string $panel = 'tenantAdmin'): void
    {
        $this->actingAs($user, Config::string('numerosis.auth.guards.tenant'));

        Filament::setCurrentPanel(Filament::getPanel($panel));
        Filament::setTenant($tenant);
    }
}
