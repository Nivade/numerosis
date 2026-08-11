<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Billing\Checkout;

use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Contracts\Billing\BillableResolver;
use Nvade\Numerosis\Contracts\Billing\CheckoutGateway;
use Nvade\Numerosis\Contracts\Billing\PaymentPlanRepository;
use Nvade\Numerosis\Data\Billing\CheckoutIntent;
use Nvade\Numerosis\Data\Billing\Intents\InlineCheckout;
use Nvade\Numerosis\Data\Tenancy\TenantRegistrationData;
use Nvade\Numerosis\Enums\BillingCycle;
use Nvade\Numerosis\Exceptions\Billing\BillingCycleRequired;
use Nvade\Numerosis\Exceptions\Billing\PaymentPlanNotFound;
use Nvade\Numerosis\Exceptions\Billing\StripePriceNotConfigured;
use Nvade\Numerosis\Exceptions\Billing\UnsupportedBillable;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\PendingTenantProvision;
use Nvade\Numerosis\Support\Numerosis;

class InlineCheckoutGateway implements CheckoutGateway
{
    public function __construct(
        private readonly BillableResolver $billables,
        private readonly PaymentPlanRepository $plans,
    ) {}

    public function begin(TenantRegistrationData $registration): CheckoutIntent
    {
        throw_if(! $registration->billing_cycle instanceof BillingCycle, BillingCycleRequired::class, 'A billing cycle is required to start a checkout.');

        $plan = $this->plans->findBySlug((string) $registration->payment_plan);

        if (! $plan) {
            throw new PaymentPlanNotFound("Payment plan not found: {$registration->payment_plan}");
        }

        // Resolved but unused: the subscription is priced later, from the
        // pending row rather than from the client. Checked here so a
        // misconfigured plan fails before the customer enters a card.
        if (! $plan->priceId($registration->billing_cycle)) {
            throw new StripePriceNotConfigured("Stripe Price ID not found for plan: {$registration->payment_plan}");
        }

        $billable = $this->billables->resolve();

        throw_unless($billable instanceof CentralUser, UnsupportedBillable::class, 'Billable must be a CentralUser to start an inline checkout.');

        $billable->createOrGetStripeCustomer();

        // Never pin payment_method_types: automatic methods mean enabling a
        // new one in the Stripe dashboard needs no code change here.
        $setupIntent = $billable->createSetupIntent([
            'automatic_payment_methods' => ['enabled' => true],
            'metadata' => ['domain' => $registration->domain],
        ]);

        // Scoped to the owner as well as the domain. Callers are expected to
        // have reserved the domain first, but an unscoped write here would
        // overwrite a stranger's SetupIntent and lock them out of their own
        // reservation.
        Numerosis::model(PendingTenantProvision::class)::where('domain', $registration->domain)
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
