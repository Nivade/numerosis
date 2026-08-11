<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Livewire\Tenant\Registration\Steps;

use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Validate;
use Nvade\Numerosis\Actions\Billing\Checkout\StartSubscriptionCheckout;
use Nvade\Numerosis\Actions\Queries\GetAuthenticatedUser;
use Nvade\Numerosis\Data\Billing\Intents\InlineCheckout;
use Nvade\Numerosis\Data\Billing\Intents\RedirectCheckout;
use Nvade\Numerosis\Data\Tenancy\TenantRegistrationData;
use Nvade\Numerosis\Enums\BillingCycle;
use Nvade\Numerosis\Exceptions\ShowsMessageToUser;
use Nvade\Numerosis\Models\Central\PaymentPlan;
use Nvade\Numerosis\Support\Numerosis;
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
     * Expected checkout refusals shown to the user: quota reached, domain
     * taken, plan retired. Held apart from field validation, because a
     * refusal is not the form complaining about an input.
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

        // Both are required to start a checkout. Send the user to whichever
        // step is missing, rather than to a validation error they cannot see.
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

        // Checkout refusals carry customer-facing copy; uncaught, Livewire
        // renders a dead button instead of the reason. Typed to
        // ShowsMessageToUser, never Throwable, so nothing unexpected leaks.
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
     * @return Collection<int, PaymentPlan>
     */
    public function getPaymentPlans(): Collection
    {
        return Numerosis::model(PaymentPlan::class)::available()
            ->orderBy('monthly_price', 'asc')
            ->get();
    }

    /**
     * @return View
     */
    public function render()
    {
        return view('numerosis::livewire.tenant.registration.wizard.steps.plan', [
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
