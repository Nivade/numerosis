<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Features\Invitations;

use Nvade\Numerosis\Contracts\NamedFeature;

/**
 * Team invitations: the invitation.show route, InvitationResource, and the
 * SendInvitationNotification listener. Remove this class from
 * config('numerosis.features') and a deployment cannot invite or accept new
 * team members — existing `invitations` rows and the Nvade\Numerosis\Contracts\Invitations\CreatesInvitedUser
 * binding are untouched, since that step (creating the invited user once
 * accepted) has no tenancy ambiguity and costs nothing to keep bound
 * unconditionally.
 *
 * bootstrap() is empty — see the class docblock pattern shared with
 * ModuleSystemFeature: routes and the resource ask Features::enabled() at
 * call time, nothing is registered at boot.
 *
 * SendInvitationNotification is auto-discovered by Laravel's event
 * discovery, so this feature cannot un-discover it — see the listener's own
 * docblock for the named exception to "a disabled feature must load
 * nothing" (Ground rules, .claude/plans/opt-in-feature-classes.md).
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
        // Nothing to register — see the class docblock.
    }
}
