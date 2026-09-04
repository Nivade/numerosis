<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Enums\Tenancy;

enum TenantProvisionStatus: string
{
    case Reserved = 'reserved';
    case Provisioning = 'provisioning';
    case Failed = 'failed';

    /**
     * Payment is still settling, only reachable for asynchronous payment
     * methods; cards resolve immediately. Provisioning proceeds regardless, so
     * this drives a banner and gates no access.
     */
    case AwaitingPayment = 'awaiting_payment';
}
