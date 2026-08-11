<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Billing;

use Nvade\Numerosis\Contracts\Tenancy\HasTenants;
use Nvade\Numerosis\Exceptions\Billing\TooManyUnpaidTenants;

/**
 * Caps how many unpaid tenants one user may hold at once.
 *
 * Tenants are provisioned before payment settles, since trials collect
 * nothing upfront — without a cap that is a free database faucet. Set
 * `numerosis.billing.unpaid_tenant_cap`, or point
 * `numerosis.billing.implementations` at your own class to change the rule.
 */
interface UnpaidTenantQuota
{
    /**
     * @throws TooManyUnpaidTenants
     */
    public function assertAvailable(HasTenants $user): void;
}
