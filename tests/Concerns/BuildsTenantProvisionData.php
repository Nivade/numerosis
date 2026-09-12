<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Concerns;

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
use App\Models\Central\TenantProvision;
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
     * The provision row every step takes, for tests that call one step
     * directly rather than going through the chain.
     */
    protected function provisionRow(CentralUser $user, string $slug, ?string $paymentPlan = null): TenantProvision
    {
        /** @var TenantProvision $provision */
        $provision = TenantProvision::query()->forceCreate([
            'slug' => $slug,
            'name' => 'Test Company',
            'global_id' => $user->global_id,
            'payment_plan' => $paymentPlan,
        ]);

        return $provision;
    }

    /**
     * The row `AddTenantOwner` takes, for an already-created tenant.
     */
    protected function ownerProvisionRow(Tenant $tenant, CentralUser $user): TenantProvision
    {
        return $this->provisionRow($user, (string) $tenant->getTenantKey());
    }
}
