<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Laravel\Cashier\Subscription;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Models\Central\Tenant;

/**
 * Suspends a tenant only once no subscription still grants it access.
 *
 * The question is whether anything valid remains, never whether something just
 * ended: Cashier has already deleted the cancelled subscription's local row by
 * the time a `customer.subscription.deleted` handler runs, and a tenant holding
 * several subscriptions must not be locked out of a workspace it is still
 * paying for.
 *
 * @method static void run(Tenant $tenant)
 */
class SuspendUnlessEntitled
{
    use AsAction;

    public function handle(Tenant $tenant): void
    {
        $stillEntitled = $tenant->subscriptions()
            ->get()
            ->contains(fn (Subscription $subscription): bool => $subscription->valid());

        if ($stillEntitled) {
            return;
        }

        SuspendTenant::run($tenant);
    }
}
