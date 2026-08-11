<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing;

use Laravel\Cashier\Cashier;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Models\Central\Tenant;

/**
 * Resolves a Stripe customer id to a tenant, or null.
 *
 * Both tenants and central users are billable, so Cashier's own lookup cannot
 * answer "which tenant is this?" on its own.
 *
 * @method static ?Tenant run(?string $customerId)
 */
class FindTenantByStripeCustomer
{
    use AsAction;

    public function handle(?string $customerId): ?Tenant
    {
        if ($customerId === null || $customerId === '') {
            return null;
        }

        $billable = Cashier::findBillable($customerId);

        return $billable instanceof Tenant ? $billable : null;
    }
}
