<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Applies a tenant's branding — primary colour, logo, favicon — to the panel
 * it is registered on.
 *
 * Owned by the module, not by core: this used to be
 * Nvade\Numerosis\Http\Middleware\ApplyPanelColorMiddleware, listed in
 * TenantAdminPanelProvider's middleware stack, which meant core carried a
 * reference to an optional app-modules package and every panel request ran a
 * branding query whether or not the module existed. It is now registered by
 * {@see \Nvade\Branding\BrandingPlugin::register()}, and that plugin is only
 * added to the panel when the tenant has the module enabled (see
 * Nvade\Numerosis\Concerns\InteractsWithTenantModules) — so with the module uninstalled
 * there is no reference to it anywhere in core, and nothing to run.
 *
 * Panel defaults are unaffected: this only overrides what the tenant has
 * actually set. The default primary colour lives on the panel itself.
 *
 * Ordering is safe wherever the plugin appends it. Filament puts
 * `panel:{id}` (SetUpPanel) first in every panel's middleware stack, so
 * Filament::getCurrentPanel() is always set by the time this runs, and
 * stancl's identification middleware is forced to highest priority by
 * TenancyServiceProvider::makeTenancyMiddlewareHighestPriority(), so tenancy
 * is initialized too.
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
