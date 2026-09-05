<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Billing\Checkout;

use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Data\Tenancy\TenantRegistrationData;
use Nvade\Numerosis\Enums\Billing\BillingCycle;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Support\Routes\RouteNames;

class StartPlanChangeCheckout
{
    use AsAction;

    public function handle(Tenant $tenant, string $planSlug, BillingCycle $cycle): string
    {
        if (! $tenant->hasStripeId()) {
            $tenant->createAsStripeCustomer();
        }

        $registration = new TenantRegistrationData(
            company_name: $tenant->name,
            domain: $tenant->id,
            global_id: (string) $tenant->owner()?->global_id,
            payment_plan: $planSlug,
            billing_cycle: $cycle,
        );

        return route(RouteNames::checkoutSubscription(), $registration->toArray());
    }
}
