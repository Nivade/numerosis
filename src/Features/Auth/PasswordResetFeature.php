<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Features\Auth;

use Nvade\Numerosis\Contracts\NamedFeature;

/**
 * Password reset: forgot-password / reset-password routes and
 * settings/password. Login itself is passwordless (one-time codes), so this
 * is a secondary surface — a deployment that only ever issues OTP codes can
 * turn it off entirely. Remove this class from config('numerosis.features')
 * and a deployment has no way to set or recover a password.
 *
 * Does NOT cover password.confirm (routes/tenant.php) — that route backs
 * Filament's own sensitive-action confirmation flow, a different concern
 * from resetting a forgotten password, and needs its own investigation
 * before being folded into this switch.
 *
 * bootstrap() is empty — routes and the two call sites that build a
 * password-reset link (UserResource's "Send Password Reset" action, the
 * settings nav item) ask Features::enabled() at call/render time.
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
        // Nothing to register — see the class docblock.
    }
}
