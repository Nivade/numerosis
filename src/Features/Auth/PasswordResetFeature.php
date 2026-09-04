<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Features\Auth;

use Nvade\Numerosis\Contracts\NamedFeature;

/**
 * Password reset: the forgot- and reset-password routes and the password
 * settings page.
 *
 * Login is password-based (Laravel Fortify). Turn this off and there is no
 * way to recover a forgotten password, only set one from the settings page
 * while already logged in. Password confirmation for sensitive actions is
 * separate and stays available regardless.
 */
class PasswordResetFeature implements NamedFeature
{
    public const NAME = 'password_reset';

    public static function featureName(): string
    {
        return self::NAME;
    }

    public function bootstrap(): void
    {
        // Nothing to register: this feature is read at call time.
    }
}
