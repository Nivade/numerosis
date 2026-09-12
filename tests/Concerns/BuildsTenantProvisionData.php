<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Concerns;

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
use Nvade\Numerosis\Data\Tenancy\BillingContribution;
use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;
use Nvade\Numerosis\Enums\Billing\BillingCycle;

/**
 * The payload `ProvisionTenant` and everything downstream of it take.
 */
trait BuildsTenantProvisionData
{
    protected function provisionData(
        CentralUser $user,
        string $slug,
        ?string $paymentPlan = null,
        ?string $stripeCustomerId = null,
        ?string $stripeSubscriptionId = null,
    ): TenantProvisionData {
        return new TenantProvisionData(
            slug: $slug,
            name: 'Test Company',
            global_id: $user->global_id,
            contributions: [new BillingContribution(
                payment_plan: $paymentPlan,
                billing_cycle: BillingCycle::Monthly,
                stripe_subscription_id: $stripeSubscriptionId,
                stripe_customer_id: $stripeCustomerId,
                central_user_id: (string) $user->id,
            )],
        );
    }

    /**
     * What `AddTenantOwner` takes. The tenant is already created by the time it
     * runs, so it reads neither the Stripe ids nor `centralUserId`.
     */
    protected function ownerProvisionData(Tenant $tenant, CentralUser $user): TenantProvisionData
    {
        return new TenantProvisionData(
            slug: (string) $tenant->getTenantKey(),
            name: 'Test Company',
            global_id: $user->global_id,
        );
    }
}
