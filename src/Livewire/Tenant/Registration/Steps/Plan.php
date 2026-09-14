<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Livewire\Tenant\Registration\Steps;

use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Validate;
use Nvade\Numerosis\Actions\Billing\Checkout\StartSubscriptionCheckout;
use Nvade\Numerosis\Actions\Queries\GetAuthenticatedUser;
use Nvade\Numerosis\Contracts\Billing\PaymentPlanRepository;
use Nvade\Numerosis\Contracts\Billing\Plan as PlanContract;
use Nvade\Numerosis\Contracts\Tenancy\ContributesProvisionData;
use Nvade\Numerosis\Contracts\Tenancy\HasTransientState;
use Nvade\Numerosis\Contracts\Tenancy\ProvisionContribution;
use Nvade\Numerosis\Data\Billing\Intents\InlineCheckout;
use Nvade\Numerosis\Data\Billing\Intents\RedirectCheckout;
use Nvade\Numerosis\Data\Tenancy\BillingContribution;
use Nvade\Numerosis\Enums\Billing\BillingCycle;
use Nvade\Numerosis\Exceptions\ShowsMessageToUser;
use Nvade\Numerosis\Exceptions\Tenancy\MissingTenantIdentity;
use Nvade\Numerosis\Livewire\Tenant\Registration\ReadsRegistrationState;
use Spatie\LivewireWizard\Components\StepComponent;

class Plan extends StepComponent implements ContributesProvisionData, HasTransientState
{
    use ReadsRegistrationState;

    public string $payment_plan = '';

    /**
     * The Stripe secrets and the UI flags. Checkout re-derives the secrets
     * from the pending provision row, so a session copy would only ever be
     * stale, and one fewer secret in the session is one fewer to tamper with.
     *
     * @return list<string>
     */
    public static function transientStateKeys(): array
    {
        return [
            'checkoutClientSecret',
            'checkoutPublishableKey',
            'isSubmitting',
            'checkoutError',
            'wizardCompleted',
        ];
    }

    /**
     * Cannot be narrowed to `BillingCycle`: Livewire's
     * `SupportNestingComponents::assignParamsToProperties()` assigns the
     * wizard's mount params to matching public properties with no cast, so the
     * string arrives raw. Read it through {@see self::cycle()}.
     */
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
     * The selected cycle as the enum, whichever shape {@see self::$billingCycle}
     * happens to hold. An unrecognised stored value falls back rather than
     * throwing, so a stale session cannot break the whole wizard.
     */
    /**
     * Reading the step's own live state gives the enum; reading it back out of
     * the wizard gives the string `StepComponent::dispatchDehydrated()` wrote.
     * Both are accepted here, since this is called from each.
     */
    public static function contribute(array $state): ?ProvisionContribution
    {
        $plan = $state['payment_plan'] ?? null;
        $cycle = $state['billingCycle'] ?? null;

        if (! is_string($plan) || $plan === '') {
            return null;
        }

        return new BillingContribution(
            payment_plan: $plan,
            billing_cycle: $cycle instanceof BillingCycle ? $cycle : BillingCycle::tryFrom(is_string($cycle) ? $cycle : ''),
        );
    }

    public function cycle(): BillingCycle
    {
        return $this->billingCycle instanceof BillingCycle
            ? $this->billingCycle
            : BillingCycle::tryFrom($this->billingCycle) ?? BillingCycle::Monthly;
    }

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

    public function back(): void
    {
        $this->previousStep();
    }

    public function register(): void
    {
        $this->validate();

        $this->checkoutError = null;

        $user = GetAuthenticatedUser::run();

        abort_if($user === null, 403);

        // Checkout refusals carry customer-facing copy; uncaught, Livewire
        // renders a dead button and no reason. Typed to ShowsMessageToUser,
        // never Throwable, so nothing unexpected leaks.
        try {
            $intent = StartSubscriptionCheckout::run($this->registrationState()->provisionData($user->global_id)->withContributions(
                array_filter([self::contribute(['payment_plan' => $this->payment_plan, 'billingCycle' => $this->cycle()])]),
            ));
        } catch (MissingTenantIdentity $e) {
            // Back to whichever step collects it; an error on this screen
            // would point at a field the user cannot see.
            $this->showStep($e->step);

            return;
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
        }
    }

    /**
     * Through the repository, never the model: that is where the retired-plan
     * scope and the catalogue cache live, and where a host's own
     * implementation is swapped in.
     *
     * @return Collection<int, PlanContract>
     */
    public function getPaymentPlans(): Collection
    {
        return resolve(PaymentPlanRepository::class)->available();
    }

    public function render(): View
    {
        return view('numerosis::livewire.tenant.registration.wizard.steps.plan', [
            'paymentPlans' => $this->getPaymentPlans(),
        ]);
    }
}
