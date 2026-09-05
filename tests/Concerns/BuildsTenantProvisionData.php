<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Concerns;

use App\Models\Central\CentralUser;
use App\Models\Central\Tenant;
use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;
use Nvade\Numerosis\Data\Tenancy\TenantRegistrationData;
use Nvade\Numerosis\Enums\Billing\BillingCycle;

/**
 * The payload `ProvisionTenant` and everything downstream of it take: a
 * registration plus the Stripe and central-user ids checkout resolved.
 */
trait BuildsTenantProvisionData
{
    protected function provisionData(
        CentralUser $user,
        string $domain,
        ?string $paymentPlan = null,
        ?string $stripeCustomerId = null,
        ?string $stripeSubscriptionId = null,
    ): TenantProvisionData {
        return new TenantProvisionData(
            registration: TenantRegistrationData::from([
                'company_name' => 'Test Company',
                'domain' => $domain,
                'payment_plan' => $paymentPlan,
                'billing_cycle' => BillingCycle::Monthly,
                'global_id' => $user->global_id,
            ]),
            stripeCustomerId: $stripeCustomerId,
            stripeSubscriptionId: $stripeSubscriptionId,
            centralUserId: (string) $user->id,
        );
    }

    /**
     * The registration-only payload `AddTenantOwner` takes. The tenant is
     * already created by the time it runs, so it reads neither the Stripe ids
     * nor `centralUserId`.
     */
    protected function ownerProvisionData(Tenant $tenant, CentralUser $user): TenantProvisionData
    {
        return new TenantProvisionData(
            registration: TenantRegistrationData::from([
                'company_name' => 'Test Company',
                'domain' => (string) $tenant->getTenantKey(),
                'global_id' => $user->global_id,
            ]),
        );
    }
}
