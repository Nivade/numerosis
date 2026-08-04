<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Features\Billing;

use Nvade\Numerosis\Contracts\NamedFeature;

/**
 * One switch for three independent listeners: SendPaymentConfirmedNotification,
 * SendPaymentFailedNotification, SendTenantSuspendedNotification. Remove this
 * class from config('numerosis.features') and a deployment sends none of the
 * three — billing events themselves (PaymentSettled, PaymentFailed,
 * TenantSuspended) still fire and drive local state (suspension, etc.),
 * only the outbound notification is suppressed.
 *
 * All three listeners are auto-discovered by Laravel's event discovery, so
 * this feature cannot un-discover them — each gates itself with an early
 * return inside handle(). Named exception to "a disabled feature must load
 * nothing" (Ground rules, .claude/plans/opt-in-feature-classes.md), same
 * shape as InvitationsFeature's SendInvitationNotification.
 *
 * bootstrap() is empty — nothing to register at boot.
 */
class BillingNotificationsFeature implements NamedFeature
{
    public const NAME = 'billing_notifications';

    public static function featureName(): string
    {
        return self::NAME;
    }

    public function bootstrap(): void
    {
        // Nothing to register — see the class docblock.
    }
}
