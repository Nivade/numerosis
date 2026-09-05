<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Features\Invitations;

use Nvade\Numerosis\Contracts\NamedFeature;

/**
 * Team invitations: the invitation route, its screens, and the
 * invitation notification.
 *
 * Remove it from `numerosis.features` and nobody can invite or accept new
 * team members. Existing invitation rows are left untouched.
 */
class InvitationsFeature implements NamedFeature
{
    public const NAME = 'invitations';

    public static function featureName(): string
    {
        return self::NAME;
    }

    public function bootstrap(): void
    {
        // Nothing to register: this feature is read at call time.
    }
}
