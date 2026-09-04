<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Billing\Checkout;

use Nvade\Numerosis\Models\Central\PendingTenantProvision;
use Stripe\PaymentMethod;

/**
 * What ResolveSetupIntent hands back: the reservation the SetupIntent belongs
 * to, plus the payment method Stripe attached to it, expanded to the object so
 * SyncBillingAddress can read billing_details.address without a second
 * Stripe call. Not a Spatie Data object, since it never crosses the wire.
 */
final readonly class ResolvedSetupIntent
{
    public function __construct(
        public PendingTenantProvision $pending,
        public PaymentMethod $paymentMethod,
    ) {}

    public function paymentMethodId(): string
    {
        return $this->paymentMethod->id;
    }
}
