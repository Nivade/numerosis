<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Nvade\Numerosis\Models\Tenant\ApiToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * The two refusals Sanctum does not make: an expired token, and a token used
 * from an address outside its allowlist.
 *
 * Expiry answers 401 with its own code instead of 403, since "your key is old"
 * and "your key may not do that" are different problems for whoever is
 * reading the integration's logs at 3am.
 */
class EnsureApiTokenIsUsable
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if (! $token instanceof ApiToken) {
            return $next($request);
        }

        if ($token->hasExpired()) {
            return new JsonResponse(['error' => 'token_expired'], Response::HTTP_UNAUTHORIZED);
        }

        if (! $token->allowsIp($request->ip())) {
            return new JsonResponse(['error' => 'address_not_allowed'], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
