<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Features\Auth;

use Nvade\Numerosis\Contracts\NamedFeature;
use Nvade\Numerosis\Features\Concerns\IsNamedFeature;

/**
 * Password reset: the forgot- and reset-password routes and the password
 * settings page. Turn it off and a forgotten password cannot be recovered at
 * all, only set from the settings page while already logged in. Password
 * confirmation for sensitive actions is separate and stays available.
 */
class PasswordResetFeature implements NamedFeature
{
    use IsNamedFeature;

    public const NAME = 'password_reset';
}
