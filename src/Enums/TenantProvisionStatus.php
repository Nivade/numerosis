<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Enums;

enum TenantProvisionStatus: string
{
    case Reserved = 'reserved';
    case Provisioning = 'provisioning';
    case Failed = 'failed';

    /**
     * Payment is still settling (async methods; unreachable for cards, which
     * resolve synchronously through Stripe's confirmSetup). Provisioning
     * proceeds regardless — see custom-checkout.md, "Provisioning and
     * settlement". Not a gate, just a banner.
     */
    case AwaitingPayment = 'awaiting_payment';
}
