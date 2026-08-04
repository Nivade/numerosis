<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Middleware;

use Nvade\Numerosis\Actions\Queries\GetAuthenticatedTenantUser;
use Nvade\Numerosis\Enums\Tenancy\Context;
use Nvade\Numerosis\Support\Cache\CacheKeys;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Stancl\Tenancy\Exceptions\TenancyNotInitializedException;

class UpdateUserLastSeenMiddleware
{
    /**
     * User::isOnline() only checks a 5-minute window, so per-request
     * precision on `last_seen_at` buys nothing. Cache::add() is atomic, so
     * concurrent requests for the same user collapse to a single write.
     */
    private const THROTTLE_SECONDS = 60;

    /**
     * @throws TenancyNotInitializedException
     */
    public function handle(Request $request, Closure $next): mixed
    {
        throw_unless(tenancy()->initialized, TenancyNotInitializedException::class, 'Tenancy is not initialized');

        // Named explicitly rather than taken from the ambient default guard,
        // and narrowed to the concrete tenant model. `last_seen_at` only exists
        // on the tenant `users` table, and the default guard is not reliably
        // the tenant one even inside tenancy: AuthGuardBootstrapper sets it on
        // initialize(), but a second initialize() for the same tenant
        // short-circuits, so anything that called Auth::shouldUse() in
        // between — Filament, a test's actingAs(), any package touching the
        // default guard — leaves it pointing at the central guard. Resolving a
        // CentralUser here writes `last_seen_at` to the central users table,
        // which has no such column.
        $user = GetAuthenticatedTenantUser::run();

        if ($user === null) {
            return $next($request);
        }

        $guard = Context::Tenant->guard();

        $userId = $user->getKey();

        if (
            (is_int($userId) || is_string($userId))
            && Cache::add(CacheKeys::lastSeenThrottle($guard, $userId), true, self::THROTTLE_SECONDS)
        ) {
            $user->updateQuietly([
                'last_seen_at' => now(),
            ]);
        }

        return $next($request);
    }
}
