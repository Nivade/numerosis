<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Livewire\Tenant\Registration;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Nvade\Numerosis\Livewire\Tenant\Registration\Steps\CompanyInfo;
use Nvade\Numerosis\Livewire\Tenant\Registration\Steps\Payment;
use Nvade\Numerosis\Livewire\Tenant\Registration\Steps\Plan;
use Nvade\Numerosis\Livewire\Tenant\Registration\Steps\TechnicalSetup;
use Nvade\Numerosis\Support\State\RegistrationState;
use Override;
use Spatie\LivewireWizard\Components\WizardComponent;

class Registration extends WizardComponent
{
    /**
     * Redeclared here (not just inherited from WizardComponent) so #[Url]
     * can be attached to it: Livewire hydrates #[Url] properties from the
     * query string during property hydration, before mountMountsWizard()
     * (in the vendor MountsWizard trait) resolves which step to show — so a
     * hard refresh lands back on the step the URL already names, with no
     * dependency on trait-vs-class mount() call ordering.
     */
    #[Url(as: 'step', history: false)]
    public ?string $currentStepName = null;

    #[Layout('layouts::app.none')]
    #[Override]
    public function render(): View
    {
        return view('numerosis::livewire.tenant.registration.wizard.index', [
            'currentStepState' => $this->getCurrentStepState(),
            'currentStepName' => $this->currentStepName,
        ]);
    }

    public function register(): void {}

    /**
     * @return list<class-string<Component>>
     */
    public function steps(): array
    {
        return [
            CompanyInfo::class,
            TechnicalSetup::class,
            Plan::class,
            Payment::class,
        ];
    }

    #[Override]
    public function stateClass(): string
    {
        return RegistrationState::class;
    }

    /**
     * Session-backed so a hard refresh restores everything filled in on
     * steps already left via showStep()/nextStep()/previousStep() — see the
     * matching write in showStep() below, which is the only place that
     * writes this key. Does not cover the step currently open and not yet
     * submitted — a refresh before clicking Continue on the open step still
     * loses that step's edits, same as before this change.
     *
     * @return array<string, array<string, mixed>>|null
     */
    public function initialState(): ?array
    {
        /** @var array<string, array<string, mixed>>|null $state */
        $state = session('registration.wizard_state');

        return $state;
    }

    /**
     * The single choke point every step transition passes through
     * (nextStep(), previousStep(), and the 'showStep' Livewire event Plan
     * uses for its validation redirects) — so persisting here, once, covers
     * all of them with no change to any individual step component.
     *
     * #[On('showStep')] has to be repeated on this override: PHP attributes
     * on a method are not inherited when a child class overrides that
     * method, and StepComponent::showStep() (called from Plan/Payment)
     * reaches this via a dispatched 'showStep' Livewire event, not a direct
     * method call — see vendor/spatie/laravel-livewire-wizard/src/Components/StepComponent.php.
     * Without the attribute here, that event stops being handled and
     * Plan's showStep('company-info') / showStep('technical-setup')
     * validation redirects silently break.
     *
     * @param  string  $toStepName
     * @param  array<string, mixed>  $currentStepState
     */
    #[On('showStep')]
    #[Override]
    public function showStep($toStepName, array $currentStepState = []): void
    {
        parent::showStep($toStepName, $currentStepState);

        session()->put('registration.wizard_state', $this->stateToPersist());
    }

    /**
     * Everything in allStepState except Plan's Stripe secrets and
     * request-local UI flags. Those never belong in the session: after
     * Part 1 of this plan, the embedded Checkout component re-derives them
     * from the pending_tenant_provisions row via ResumeCheckout, so a
     * session-stored client secret would only ever be stale. Matches the
     * "no session carrier means no tamper surface" principle in
     * .claude/plans/custom-checkout.md.
     *
     * @return array<string, array<string, mixed>>
     */
    private function stateToPersist(): array
    {
        $state = $this->allStepState;

        $planAlias = resolve('livewire.finder')->normalizeName(Plan::class);

        if ($planAlias !== null && isset($state[$planAlias]) && is_array($state[$planAlias])) {
            unset(
                $state[$planAlias]['checkoutClientSecret'],
                $state[$planAlias]['checkoutPublishableKey'],
                $state[$planAlias]['isSubmitting'],
                $state[$planAlias]['checkoutError'],
                $state[$planAlias]['wizardCompleted'],
            );
        }

        return $state;
    }

    public function getFormalCurrentStepName(): string
    {
        // Try to resolve the current step's 1-based index from the wizard's step names
        $index = $this->stepNames()->search(fn (string $step) => $step === $this->currentStepName);

        if ($index !== false) {
            // Convert zero-based index to 1-based step number and delegate
            return $this->getFormalStepNameFor(((int) $index) + 1);
        }

        // Fallback: format the current step name directly if not found in the steps list
        $name = str_replace('-', ' ', (string) $this->currentStepName);

        return ucwords($name);
    }

    public function getFormalStepNameFor(int $stepNumber): string
    {
        $steps = $this->steps();

        // Treat a given step number as 1-based for usability
        $index = $stepNumber - 1;

        if ($index < 0 || $index >= count($steps)) {
            return '';
        }

        $stepClass = $steps[$index];

        // Derive the step slug from the class name, mirroring Livewire Wizard's naming
        $slug = Str::kebab(class_basename($stepClass));

        $name = str_replace('-', ' ', $slug);

        return ucwords($name);
    }

    public function getCurrentStepNumber(): int
    {
        $index = $this->stepNames()->search(fn (string $step) => $step === $this->currentStepName);

        return $index !== false ? ((int) $index) + 1 : 1;
    }
}
