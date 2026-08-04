<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Billing;

use Nvade\Numerosis\Contracts\Tenancy\HasTenants;
use Nvade\Numerosis\Exceptions\Billing\TooManyUnpaidTenants;

// See .claude/rules/billing-checkout.md.
interface UnpaidTenantQuota
{
    /**
     * @throws TooManyUnpaidTenants
     */
    public function assertAvailable(HasTenants $user): void;
}
