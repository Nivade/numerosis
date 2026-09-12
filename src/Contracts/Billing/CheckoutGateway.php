<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Billing;

use Nvade\Numerosis\Data\Billing\CheckoutIntent;
use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;

interface CheckoutGateway
{
    public function begin(TenantProvisionData $registration): CheckoutIntent;
}
