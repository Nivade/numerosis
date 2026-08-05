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

        // Not used yet — the price is chosen again in CreateInlineSubscription,
        // once the pending row (not the client) is the source of truth for
        // plan/cycle. Checked here anyway so a misconfigured plan fails at the
        // start of checkout, not after the customer has entered a card.
        if (! $plan->priceId($registration->billing_cycle)) {
            throw new StripePriceNotConfigured("Stripe Price ID not found for plan: {$registration->payment_plan}");
        }

        $billable = $this->billables->resolve();

        throw_unless($billable instanceof CentralUser, UnsupportedBillable::class, 'Billable must be a CentralUser to start an inline checkout.');

        $billable->createOrGetStripeCustomer();

        // Never pin payment_method_types — that single line would turn every
        // future payment method into a code change. See custom-checkout.md,
        // "Designing for more payment methods".
        $setupIntent = $billable->createSetupIntent([
            'automatic_payment_methods' => ['enabled' => true],
            'metadata' => ['domain' => $registration->domain],
        ]);

        // Scoped to the reservation's owner as well as its domain. The only
        // caller (StartSubscriptionCheckout) runs ReserveTenantDomain first,
        // which already refuses a domain claimed by someone else — but this
        // action does not enforce that itself, and an unscoped update here
        // would overwrite a stranger's stored SetupIntent, leaving the
        // rightful owner resuming a checkout against a Stripe customer that
        // is not theirs (ResolveSetupIntent then locks them out entirely).
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
