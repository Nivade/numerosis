<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\RequirePassword;

/**
 * `password.confirm` for everyone who has a password, and a pass-through for
 * everyone who does not.
 *
 * A user who registered through OAuth has `users.password` null. Fortify's
 * confirm-password screen ends in `Hash::check($password, null)`, which can
 * never return true, so plain `password.confirm` on `social.destroy` locked
 * those users out of disconnecting anything. `SocialAccountPolicy::delete()`
 * still refuses to remove their last credential, which is the protection that
 * actually applies to them. `routes/web.php` gates `settings/password` on
 * `PasswordResetFeature` for the same underlying reason.
 */
class RequirePasswordIfSet extends RequirePassword
{
    /**
     * @param  \Illuminate\Http\Request  $request
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
