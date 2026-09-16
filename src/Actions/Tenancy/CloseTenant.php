<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Laravel\Cashier\Subscription;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Events\Tenancy\TenantClosed;
use Nvade\Numerosis\Models\Central\Tenant;

/**
 * Closure the owner asked for: access stops now, the data stays for the
 * recovery window {@see Tenant::purgeAt()}, and billing runs to the end of the
 * period the customer already paid for.
 *
 * Subscriptions are cancelled before `closed_at` is written, so a retry after
 * a Stripe failure still reaches the cancel.
 *
 * @method static void run(Tenant $tenant, bool $acknowledgeOutstandingBalance = false)
 */
class CloseTenant
{
    use AsAction;

    public function handle(Tenant $tenant, bool $acknowledgeOutstandingBalance = false): void
    {
        if ($tenant->isClosed()) {
            return;
        }

        AssertTenantClosable::run($tenant, $acknowledgeOutstandingBalance);

        $tenant->subscriptions()
            ->get()
            ->each(function (Subscription $subscription): void {
                if ($subscription->canceled() || ! $subscription->valid()) {
                    return;
                }

                $subscription->cancel();
            });

        $tenant->update(['closed_at' => now()]);

        event(new TenantClosed(
            (string) $tenant->getTenantKey(),
            $tenant->owner()?->global_id,
            $tenant->closed_at ?? now(),
        ));
    }
}
