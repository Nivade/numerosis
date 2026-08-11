<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Features\Billing;

use Nvade\Numerosis\Contracts\NamedFeature;

/**
 * The payment-confirmed, payment-failed and tenant-suspended notifications.
 *
 * Remove it from `numerosis.features` to send none of them. The billing
 * events still fire and still drive state such as suspension; only the
 * outbound notification is suppressed.
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
        // Nothing to register: this feature is read at call time.
    }
}
