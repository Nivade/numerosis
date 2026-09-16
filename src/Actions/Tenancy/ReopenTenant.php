<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Laravel\Cashier\Subscription;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Events\Tenancy\TenantReopened;
use Nvade\Numerosis\Models\Central\Tenant;

/**
 * Undoes {@see CloseTenant} inside the recovery window. Returns whether the
 * tenant has live billing afterwards: false means every subscription has
 * lapsed and the owner has to buy a new one.
 *
 * @method static bool run(Tenant $tenant)
 */
class ReopenTenant
{
    use AsAction;

    public function handle(Tenant $tenant): bool
    {
        if (! $tenant->isClosed()) {
            return $tenant->subscriptions()->get()->contains(fn (Subscription $subscription): bool => $subscription->valid());
        }

        $resumed = $tenant->subscriptions()
            ->get()
            ->filter(fn (Subscription $subscription): bool => $subscription->onGracePeriod())
            ->each(fn (Subscription $subscription) => $subscription->resume())
            ->isNotEmpty();

        $tenant->update(['closed_at' => null]);

        event(new TenantReopened(
            (string) $tenant->getTenantKey(),
            $tenant->owner()?->global_id,
            $resumed,
        ));

        return $resumed;
    }
}
