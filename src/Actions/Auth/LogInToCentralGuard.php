<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Auth;

use Illuminate\Http\Request;
use Nvade\Numerosis\Actions\Queries\GetAuthenticatedUser;
use Nvade\Numerosis\Models\User;
use RuntimeException;

/**
 * The second half of the old `LoginUser` action, run as a step in Fortify's
 * `authenticateThrough()` pipeline (`NumerosisServiceProvider::packageBooted()`),
 * after `AttemptToAuthenticate`/`PrepareAuthenticatedSession` have already
 * logged the request's own guard in. Dual-guard login itself still lives in
 * `LoginUser`, which this delegates to — this class only adapts it to the
 * pipeline's `(Request, Closure): mixed` shape.
 */
class LogInToCentralGuard
{
    public function handle(Request $request, callable $next): mixed
    {
        $user = GetAuthenticatedUser::run();

        throw_unless($user instanceof User, RuntimeException::class, 'Expected an authenticated Nvade\Numerosis\Models\User instance.');

        LoginUser::run($user, $request->boolean('remember'));

        return $next($request);
    }
}
