<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Billing;

use Laravel\Cashier\Subscription;
use Nvade\Numerosis\Data\Billing\SubscriptionData;

interface SubscriptionRepository
{
    public function findByStripeId(string $stripeId): ?Subscription;

    public function record(SubscriptionData $data): Subscription;
}
