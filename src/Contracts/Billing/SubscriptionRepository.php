<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Billing;

use Laravel\Cashier\Subscription;
use Nvade\Numerosis\Data\Billing\SubscriptionData;

/**
 * Typed against Cashier's `Subscription`, deliberately, not this package's
 * own subclass: `ArchTest`'s "billing contracts do not depend on app models"
 * forbids `Nvade\Numerosis\Contracts\Billing` from referencing
 * `Nvade\Numerosis\Models`. Callers that need a column this package adds
 * (`payment_plan_id`) have to narrow to {@see \Nvade\Numerosis\Models\Central\Subscription}
 * themselves — the concrete implementation returns one.
 */
interface SubscriptionRepository
{
    public function findByStripeId(string $stripeId): ?Subscription;

    public function record(SubscriptionData $data): Subscription;
}
