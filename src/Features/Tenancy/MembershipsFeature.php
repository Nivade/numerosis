<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Features\Tenancy;

use Nvade\Numerosis\Contracts\NamedFeature;

/**
 * The tenant panel's Team screens, where an owner manages who belongs to
 * the tenant.
 *
 * Remove it from `numerosis.features` to hide them. Membership rows are
 * still written by provisioning and invitation-accept; this gates only the
 * screens that list and edit them. Inviting people has its own switch.
 */
class MembershipsFeature implements NamedFeature
{
    public const NAME = 'tenancy.memberships';

    public static function featureName(): string
    {
        return self::NAME;
    }

    public function bootstrap(): void
    {
        // Nothing to register: this feature is read at call time.
    }
}
