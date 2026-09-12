<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Billing;

use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Contracts\Billing\BillableResolver;
use Nvade\Numerosis\Contracts\Billing\BillableUser;
use Nvade\Numerosis\Contracts\Billing\CheckoutGateway;
use Nvade\Numerosis\Contracts\Billing\PaymentPlanRepository;
use Nvade\Numerosis\Data\Billing\CheckoutIntent;
use Nvade\Numerosis\Data\Billing\Intents\InlineCheckout;
use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;
use Nvade\Numerosis\Enums\Billing\BillingCycle;
use Nvade\Numerosis\Exceptions\Billing\BillingCycleRequired;
use Nvade\Numerosis\Exceptions\Billing\StripePriceNotConfigured;
use Nvade\Numerosis\Exceptions\Billing\UnsupportedBillable;
use Nvade\Numerosis\Models\Central\TenantProvision;
use Nvade\Numerosis\Numerosis;

class InlineCheckoutGateway implements CheckoutGateway
{
    public function __construct(
        private readonly BillableResolver $billables,
        private readonly PaymentPlanRepository $plans,
    ) {}

    public function begin(TenantProvisionData $registration): CheckoutIntent
    {
        throw_if(! $registration->billing_cycle instanceof BillingCycle, BillingCycleRequired::class, 'A billing cycle is required to start a checkout.');

        $plan = $this->plans->findBySlugOrFail((string) $registration->payment_plan);

        // The subscription is priced later from the pending row. Checked here
        // so a misconfigured plan fails before the customer enters a card.
        if (! $plan->priceId($registration->billing_cycle)) {
            throw new StripePriceNotConfigured("Stripe Price ID not found for plan: {$registration->payment_plan}");
        }

        $billable = $this->billables->resolve();

        throw_unless($billable instanceof BillableUser, UnsupportedBillable::class, 'Billable must be a central user to start an inline checkout.');

        $billable->createOrGetStripeCustomer();

        // Never pin payment_method_types. Automatic methods let a new method
        // be enabled in the Stripe dashboard with no code change here.
        $setupIntent = $billable->createSetupIntent([
            'automatic_payment_methods' => ['enabled' => true],
            'metadata' => ['slug' => $registration->slug],
        ]);

        // Scoped to the owner as well as the domain. An unscoped write would
        // overwrite a stranger's SetupIntent and lock them out of their
        // reservation.
        Numerosis::model(TenantProvision::class)::where('slug', $registration->slug)
            ->where('global_id', $registration->global_id)
            ->update([
                'payment_plan' => $registration->payment_plan,
                'billing_cycle' => $registration->billing_cycle->value,
                'stripe_setup_intent_id' => $setupIntent->id,
            ]);

        return new InlineCheckout(
            clientSecret: (string) $setupIntent->client_secret,
            publishableKey: Config::string('cashier.key'),
        );
    }
}
