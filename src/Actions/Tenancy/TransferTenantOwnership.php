<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Illuminate\Support\Facades\Config;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Actions\Billing\SyncTenantToStripe;
use Nvade\Numerosis\Enums\Tenancy\MembershipRole;
use Nvade\Numerosis\Events\Tenancy\TenantOwnershipTransferred;
use Nvade\Numerosis\Models\Central\Membership;
use Nvade\Numerosis\Models\Central\Tenant;

/**
 * The Stripe customer is the tenant, not the owner, so nothing moves between
 * customers here: the customer's name and email describe whoever owns the
 * tenant now, which is what `SyncTenantToStripe` re-sends.
 *
 * @method static void run(Tenant $tenant, Membership $target)
 */
class TransferTenantOwnership
{
    use AsAction;

    public function handle(Tenant $tenant, Membership $target): void
    {
        if ($target->isOwner() && $target->tenant_id === $tenant->id) {
            return;
        }

        AssertOwnershipTransferable::run($tenant, $target);

        $target->getConnection()->transaction(function () use ($target): void {
            $current = Membership::query()
                ->where('tenant_id', $target->tenant_id)
                ->where('role', MembershipRole::Owner->value)
                ->lockForUpdate()
                ->first();

            $target->refresh();

            if ($target->isOwner()) {
                return;
            }

            $current?->update(['role' => MembershipRole::Admin]);
            $target->update(['role' => MembershipRole::Owner]);

            event(new TenantOwnershipTransferred(
                $target->tenant_id,
                $current?->global_user_id,
                $target->global_user_id,
            ));
        });

        if (Config::boolean('numerosis.billing.sync.stripe_customer', true)) {
            SyncTenantToStripe::dispatch($tenant);
        }
    }
}
