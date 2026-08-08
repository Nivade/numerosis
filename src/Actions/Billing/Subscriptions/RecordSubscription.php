<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing\Subscriptions;

use Laravel\Cashier\Subscription;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Contracts\Billing\SubscriptionRepository;
use Nvade\Numerosis\Data\Billing\SubscriptionData;

/**
 * @method static Subscription run(SubscriptionData $data)
 */
class RecordSubscription
{
    use AsAction;

    public function __construct(private readonly SubscriptionRepository $subscriptions) {}

    public function handle(SubscriptionData $data): Subscription
    {
        return $this->subscriptions->record($data);
    }
}
