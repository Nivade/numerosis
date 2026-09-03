<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Stancl\Tenancy\Tenancy;

/**
 * `Livewire::setUpdateRoute()` registers one global `/livewire/update`
 * route for every page, and it carries no `{tenant}` parameter. Under
 * `IdentificationMode::Path`, applying `Stancl\Tenancy\Middleware\
 * InitializeTenancyByPath` to that route throws — it asserts
 * `$route->parameterNames()[0] === 'tenant'`, and index 0 does not exist
 * on a route with no parameters at all. The practical effect before this
 * class existed: every `mountAction`/`wire:model`/form-submit commit under
 * path mode failed tenancy identification silently (a warning-turned-
 * exception the Livewire JS swallows without a visible console error), so
 * no Livewire interaction worked on a path-mode tenant panel — found via a
 * browser test whose modal never mounted for this reason rather than for a
 * Livewire/Alpine bug.
 *
 * The route itself can't carry `{tenant}` — Livewire's client always posts
 * to the one URL `Livewire::setUpdateRoute()` registered, regardless of
 * which page issued the commit. Instead, the tenant is read off the
 * `Referer` header's first path segment, which is what the browser was
 * actually looking at when it fired the commit. A same-origin `fetch()`
 * (which is what Livewire's JS makes) sends `Referer` under the default
 * `strict-origin-when-cross-origin` policy, so this is reliable in
 * practice; if it is ever missing (a privacy extension, a non-browser
 * client), the request degrades to an un-initialized central context
 * exactly as a Livewire commit from a genuine central page already does,
 * rather than throwing.
 */
class InitializeLivewireTenancyByPath
{
    public function __construct(private readonly Tenancy $tenancy) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $id = $this->tenantIdFromReferer($request);

        if ($id !== null) {
            $tenant = tenancy()->find($id);

            if ($tenant !== null) {
                $this->tenancy->initialize($tenant);
            }
        }

        return $next($request);
    }

    private function tenantIdFromReferer(Request $request): ?string
    {
        $referer = $request->headers->get('referer');

        if (! is_string($referer) || $referer === '') {
            return null;
        }

        $path = trim((string) parse_url($referer, PHP_URL_PATH), '/');

        if ($path === '') {
            return null;
        }

        $segment = explode('/', $path)[0];

        return $segment === '' ? null : $segment;
    }
}
