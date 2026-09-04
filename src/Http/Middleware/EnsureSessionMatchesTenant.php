<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthManager;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Nvade\Numerosis\Models\Central\Tenant;

/**
 * Drops the tenant guard's session state when the session was established for
 * a different tenant. One session cookie spans the apex domain and the tenant
 * guard stores nothing but a per-database primary key, so the same id
 * identifies a different person in the next tenant. Clearing it fails closed;
 * `Authenticate` re-establishes the right user from the central guard.
 */
class EnsureSessionMatchesTenant
{
    public const SESSION_KEY = 'tenancy.session_tenant';

    public function __construct(
        private readonly AuthManager $auth,
        private readonly Repository $config,
    ) {}

    public function handle(Request $request, Closure $next): mixed
    {
        if (! tenancy()->initialized || ! $request->hasSession() || ! $request->session()->isStarted()) {
            return $next($request);
        }

        $currentTenant = tenant();

        if (! $currentTenant instanceof Tenant) {
            return $next($request);
        }

        $session = $request->session();
        $tenantKey = $currentTenant->getTenantKey();

        if ($session->get(self::SESSION_KEY) === $tenantKey) {
            return $next($request);
        }

        $guard = $this->auth->guard($this->config->string('numerosis.auth.guards.tenant'));

        if ($guard instanceof SessionGuard) {
            $session->forget($guard->getName());
            Cookie::queue(Cookie::forget($guard->getRecallerName()));
        }

        $session->put(self::SESSION_KEY, $tenantKey);

        return $next($request);
    }
}
