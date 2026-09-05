<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Features\Auth;

use Nvade\Numerosis\Contracts\NamedFeature;
use Nvade\Numerosis\Support\Features;

/**
 * Password reset: the forgot- and reset-password routes and the password
 * settings page. Turn it off and a forgotten password cannot be recovered at
 * all, only set from the settings page while already logged in. Password
 * confirmation for sensitive actions is separate and stays available.
 */
class PasswordResetFeature implements NamedFeature
{
    public const NAME = 'password_reset';

    public static function featureName(): string
    {
        return self::NAME;
    }

    public static function available(): bool
    {
        return Features::enabled(self::NAME);
    }

    public function bootstrap(): void
    {
        // Nothing to register: this feature is read at call time.
    }
}
