<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Livewire\Tenant\Registration\Steps;

use Nvade\Numerosis\Actions\Billing\Checkout\StartSubscriptionCheckout;
use Nvade\Numerosis\Actions\Queries\GetAuthenticatedUser;
use Nvade\Numerosis\Data\Billing\Intents\InlineCheckout;
use Nvade\Numerosis\Data\Billing\Intents\RedirectCheckout;
use Nvade\Numerosis\Data\Tenancy\TenantRegistrationData;
use Nvade\Numerosis\Enums\BillingCycle;
use Nvade\Numerosis\Exceptions\ShowsMessageToUser;
use Nvade\Numerosis\Models\Central\PaymentPlan;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Validate;
use Spatie\LivewireWizard\Components\StepComponent;

class Plan extends StepComponent
{
    public string $payment_plan = '';

    public BillingCycle|string $billingCycle = BillingCycle::Monthly;

    #[Validate('required|boolean|accepted')]
    public bool $terms = false;

    public bool $isSubmitting = false;

    public bool $wizardCompleted = false;

    /**
     * Expected, user-facing checkout refusals (quota reached, domain taken,
     * plan retired). Kept separate from flux:error for the same reason
     * Nvade\Numerosis\Livewire\Billing\Checkout keeps $paymentError separate — a rejection
     * is not the form complaining about a field, and pointing it at one would
     * be a lie.
     */
    public ?string $checkoutError = null;

    /** Handed to the Payment step via RegistrationState::checkout(). */
    public ?string $checkoutClientSecret = null;

    public ?string $checkoutPublishableKey = null;

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'payment_plan' => ['required', 'string'],
            'terms' => ['required', 'boolean', 'accepted'],
        ];
    }

    public function updatedPayment_plan(): void
    {
        $this->validateOnly('payment_plan');
    }

    public function updatedTerms(): void
    {
        $this->validateOnly('terms');
    }

    public function setBillingCycle(BillingCycle $billingCycle): void
    {
        $this->billingCycle = $billingCycle;
    }

    public function back(): void
    {
        $this->previousStep();
    }

    public function register(): void
    {
        $this->validate();

        $this->checkoutError = null;

        $companyName = $this->state()->get('company_name');
        $domain = $this->state()->get('domain');

        /**
         * TenantRegistrationData requires both of these. Redirecting without them makes
         * the checkout route throw a ValidationException, which sends the user
         * back to the wizard with no visible error — an apparently dead button.
         * Send them to the step that is actually missing instead.
         */
        if (blank($companyName)) {
            $this->showStep('company-info');

            return;
        }

        if (blank($domain)) {
            $this->showStep('technical-setup');

            return;
        }

        $user = GetAuthenticatedUser::run();

        abort_if($user === null, 403);

        $billingCycle = $this->billingCycle instanceof BillingCycle
            ? $this->billingCycle
            : BillingCycle::from($this->billingCycle);

        // TooManyUnpaidTenants, DomainAlreadyClaimed, PaymentPlanNotFound and
        // StripePriceNotConfigured are all DomainExceptions — expected
        // refusals whose messages are written as customer copy. Uncaught,
        // Livewire renders them as a 500 and the user sees a dead button
        // instead of the reason. Typed to ShowsMessageToUser, never Throwable,
        // per .claude/rules/exception-handling.md.
        try {
            $intent = StartSubscriptionCheckout::run(new TenantRegistrationData(
                company_name: (string) $companyName,
                domain: (string) $domain,
                global_id: $user->global_id,
                payment_plan: $this->payment_plan,
                billing_cycle: $billingCycle,
            ));
        } catch (ShowsMessageToUser $e) {
            $this->checkoutError = $e->getMessage();

            return;
        }

        if ($intent instanceof InlineCheckout) {
            $this->checkoutClientSecret = $intent->clientSecret;
            $this->checkoutPublishableKey = $intent->publishableKey;
            $this->wizardCompleted = true;
            $this->nextStep();

            return;
        }

        if ($intent instanceof RedirectCheckout) {
            $this->redirect($intent->url);

            return;
        }
    }

    public function check(): void {}

    /**
     * Get all active payment plans from database.
     */
    /**
     * @return Collection<int, PaymentPlan>
     */
    public function getPaymentPlans(): Collection
    {
        return PaymentPlan::available()
            ->orderBy('monthly_price', 'asc')
            ->get();
    }

    /**
     * Render the Livewire plan view with active payment plans.
     *
     * @return View
     */
    public function render()
    {
        return view('livewire.tenant.registration.wizard.steps.plan', [
            'paymentPlans' => $this->getPaymentPlans(),
        ]);
    }

    public function monthly(): void
    {
        $this->setBillingCycle(BillingCycle::Monthly);
    }

    public function yearly(): void
    {
        $this->setBillingCycle(BillingCycle::Yearly);
    }
}
