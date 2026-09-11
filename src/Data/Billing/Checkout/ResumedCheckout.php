<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Data\Billing\Checkout;

use Nvade\Numerosis\Models\Central\PendingTenantProvision;

/**
 * What ResumeCheckout hands back: the reservation, the SetupIntent's client
 * secret to re-mount an Element against, and whether the SetupIntent already
 * succeeded, in which case the checkout is past collection and the caller
 * should settle without rendering an Element.
 */
final readonly class ResumedCheckout
{
    public function __construct(
        public PendingTenantProvision $pending,
        public string $clientSecret,
        public bool $alreadySucceeded,
    ) {}
}
