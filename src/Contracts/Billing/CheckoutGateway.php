<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Billing;

use Nvade\Numerosis\Data\Billing\CheckoutIntent;
use Nvade\Numerosis\Data\Tenancy\TenantRegistrationData;

interface CheckoutGateway
{
    public function begin(TenantRegistrationData $registration): CheckoutIntent;
}
