<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Billing;

use Nvade\Numerosis\Data\Billing\SubscriptionData;
use Laravel\Cashier\Subscription;

interface SubscriptionRepository
{
    public function findByStripeId(string $stripeId): ?Subscription;

    public function record(SubscriptionData $data): Subscription;
}
