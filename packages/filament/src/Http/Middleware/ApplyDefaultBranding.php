<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Nvade\Numerosis\Models\Central\Tenant;

/**
 * Applies a tenant's branding — primary colour, logo, favicon — to the panel
 * it is registered on.
 *
 * Registered by a branding module's own plugin, not by the panel, so a
 * deployment without that module runs no branding query at all.
 *
 * Only overrides what a tenant has actually set; panel defaults stand
 * otherwise. Safe at any position in the stack — both the current panel and
 * the current tenant are resolved before any panel middleware runs.
 */
class ApplyDefaultBranding
{
    public function handle(Request $request, Closure $next): mixed
    {
        $panel = Filament::getCurrentPanel();
        $tenant = tenancy()->tenant;

        // Guarded rather than null-safe: `Str::ucfirst()` is `string`-typed, so
        // a central request that reached a panel used to be a TypeError here.
        if ($panel !== null && $tenant instanceof Tenant) {
            $panel->brandName(Str::ucfirst($tenant->name));
        }

        return $next($request);
    }
}
