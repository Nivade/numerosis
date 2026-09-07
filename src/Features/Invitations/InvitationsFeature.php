<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Features\Invitations;

use Nvade\Numerosis\Contracts\NamedFeature;
use Nvade\Numerosis\Features\Concerns\IsNamedFeature;

/**
 * Team invitations: the invitation route, its screens, and the
 * invitation notification.
 *
 * Remove it from `numerosis.features` and nobody can invite or accept new
 * team members. Existing invitation rows are left untouched.
 */
class InvitationsFeature implements NamedFeature
{
    use IsNamedFeature;

    public const NAME = 'invitations';
}
