<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Features\Billing;

use Nvade\Numerosis\Contracts\NamedFeature;
use Nvade\Numerosis\Features\Concerns\IsNamedFeature;

/**
 * The payment-confirmed, payment-failed and tenant-suspended notifications.
 *
 * Remove it from `numerosis.features` to send none of them. The billing
 * events still fire and still drive state such as suspension; only the
 * outbound notification is suppressed.
 */
class BillingNotificationsFeature implements NamedFeature
{
    use IsNamedFeature;

    public const NAME = 'billing_notifications';
}
