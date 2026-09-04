<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Billing;

use Laravel\Cashier\Subscription;
use Nvade\Numerosis\Data\Billing\SubscriptionData;

/**
 * Typed against Cashier's `Subscription` and never this package's subclass,
 * since `ArchTest` forbids `Nvade\Numerosis\Contracts\Billing` from
 * referencing `Nvade\Numerosis\Models`. A caller needing `payment_plan_id`
 * narrows to {@see \Nvade\Numerosis\Models\Central\Subscription} itself;
 * the concrete implementation returns one.
 */
interface SubscriptionRepository
{
    public function findByStripeId(string $stripeId): ?Subscription;

    public function record(SubscriptionData $data): Subscription;
}
