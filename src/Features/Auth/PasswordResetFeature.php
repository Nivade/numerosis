<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Features\Auth;

use Nvade\Numerosis\Contracts\NamedFeature;

/**
 * Password reset: the forgot- and reset-password routes and the password
 * settings page.
 *
 * Login is passwordless, so this is a secondary surface — turn it off and
 * there is no way to set or recover a password. Password *confirmation* for
 * sensitive actions is separate and stays available.
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
