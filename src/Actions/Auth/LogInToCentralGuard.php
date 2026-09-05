<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Auth;

use Illuminate\Http\Request;
use Nvade\Numerosis\Actions\Queries\GetAuthenticatedUser;
use Nvade\Numerosis\Models\User;
use RuntimeException;

/**
 * A step in Fortify's `authenticateThrough()` pipeline, running after
 * `AttemptToAuthenticate`/`PrepareAuthenticatedSession` have logged the
 * request's own guard in. Dual-guard login lives in `LoginUser`, which this
 * delegates to; this class only adapts it to the pipeline's
 * `(Request, Closure): mixed` shape.
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
