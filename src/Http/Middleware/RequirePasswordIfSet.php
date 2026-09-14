<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Http\Request;

/**
 * `password.confirm` for everyone who has a password, and a pass-through for
 * everyone who does not. A user who registered through OAuth has
 * `users.password` null, and Fortify's confirm-password screen ends in
 * `Hash::check($password, null)`, which can never return true, so plain
 * `password.confirm` locks those users out of the route entirely.
 */
class RequirePasswordIfSet extends RequirePassword
{
    /**
     * @param  Request  $request
     * @param  string|null  $redirectToRoute
     * @param  string|int|null  $passwordTimeoutSeconds
     * @return mixed
     */
    public function handle($request, Closure $next, $redirectToRoute = null, $passwordTimeoutSeconds = null)
    {
        $user = $request->user();

        if ($user === null || blank($user->getAuthPassword())) {
            return $next($request);
        }

        return parent::handle($request, $next, $redirectToRoute, $passwordTimeoutSeconds);
    }
}
