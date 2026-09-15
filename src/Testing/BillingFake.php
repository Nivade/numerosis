<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Testing;

use Nvade\Numerosis\Contracts\Billing\CheckoutGateway;
use Nvade\Numerosis\Contracts\Tenancy\ProvisionsTenant;

/**
 * Lives here, not on `BillingService`, so a published `src/` autoload never
 * instantiates a test double.
 */
final class BillingFake
{
    public static function swap(): FakeCheckoutGateway
    {
        $fake = new FakeCheckoutGateway;

        app()->instance(CheckoutGateway::class, $fake);
        app()->instance(ProvisionsTenant::class, $fake);

        return $fake;
    }
}
