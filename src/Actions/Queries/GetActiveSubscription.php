<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Queries;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Models\Central\Tenant;

/**
 * The subscription a tenant is currently being served under. `valid()` covers
 * trialing and grace periods, which is what "currently" means to a customer.
 *
 * @method static Subscription|null run(Tenant $tenant)
 */
class GetActiveSubscription
{
    use AsAction;

    public function handle(Tenant $tenant): ?Subscription
    {
        return $tenant->subscriptions()->get()
            ->first(fn (Subscription $subscription): bool => $subscription->valid());
    }
}
