<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Laravel\Cashier\Subscription;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Models\Central\Tenant;

/**
 * Suspends a tenant only once no subscription still grants it access.
 *
 * The question is whether anything valid remains, distinct from whether
 * something just ended. Cashier has already deleted the cancelled
 * subscription's local row by the time a `customer.subscription.deleted`
 * handler runs, and a tenant may hold several.
 *
 * @method static void run(Tenant $tenant)
 */
class SuspendUnlessEntitled
{
    use AsAction;

    public function handle(Tenant $tenant): void
    {
        // Closure already blocks access and says why; suspending on top of it
        // sends dunning mail for a subscription the owner cancelled.
        if ($tenant->isClosed()) {
            return;
        }

        $stillEntitled = $tenant->subscriptions()
            ->get()
            ->contains(fn (Subscription $subscription): bool => $subscription->valid());

        if ($stillEntitled) {
            return;
        }

        SuspendTenant::run($tenant);
    }
}
