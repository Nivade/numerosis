<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Billing\Resolvers;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Contracts\Billing\UnpaidTenantQuota;
use Nvade\Numerosis\Contracts\Tenancy\HasTenants;
use Nvade\Numerosis\Exceptions\Billing\TooManyUnpaidTenants;
use Nvade\Numerosis\Models\Central\Tenant;

class DefaultUnpaidTenantQuota implements UnpaidTenantQuota
{
    public function assertAvailable(HasTenants $user): void
    {
        $max = Config::integer('numerosis.billing.unpaid_tenant_cap', 2);

        // "Unpaid" is anything short of a confirmed active subscription —
        // trialing included, since a trial collects zero money upfront, the
        // same reasoning custom-checkout.md applies to an authorised-but-
        // still-settling payment.
        // Eager-loaded because latestSubscription() is a query, not a
        // relation, and would otherwise fire once per owned tenant on the
        // checkout hot path. subscriptions() is already ordered latest-first,
        // so the loaded collection's first element is the same row it
        // would have returned.
        /** @var Collection<int, Tenant> $owned */
        $owned = $user->tenants()
            ->wherePivot('role', 'owner')
            ->with('subscriptions')
            ->get();

        $unpaid = $owned
            ->filter(fn (Tenant $tenant): bool => $tenant->subscriptions->first()?->stripe_status !== 'active')
            ->count();

        throw_if($unpaid >= $max, TooManyUnpaidTenants::class, "You already have {$max} unpaid workspace(s). Please complete payment on an existing workspace before creating another.");
    }
}
