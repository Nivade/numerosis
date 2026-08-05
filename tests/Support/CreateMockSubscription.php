<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Support;

use App\Models\Central\Subscription;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Models\Central\SubscriptionItem;
use Spatie\LaravelData\Data;

class CreateMockSubscription
{
    use AsAction;

    public function handle(Data $data): Subscription
    {
        /** @var Subscription $subscription */
        $subscription = Subscription::factory()->count(1)->create($data->except('items')->toArray())->first();

        SubscriptionItem::factory(1)->create([
            'subscription_id' => $subscription->id,
        ]);

        return $subscription;
    }
}
