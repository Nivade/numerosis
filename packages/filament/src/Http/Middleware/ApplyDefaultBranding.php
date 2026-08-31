<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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

        $panel?->brandName(Str::ucfirst(tenant()?->name));

        return $next($request);
    }
}
