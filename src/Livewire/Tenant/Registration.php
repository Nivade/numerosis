<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Livewire\Tenant;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Nvade\Numerosis\Livewire\Tenant\Registration\Steps\Plan;
use Nvade\Numerosis\Support\Tenancy\RegistrationState;
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
     * `WizardComponent::getCurrentStepState()` hands each step component a
     * `wizardClassName` of `static::class` — the raw FQCN — which every
     * `StepComponent::nextStep()`/`previousStep()`/`showStep()` call then
     * uses as `->to($this->wizardClassName)` to target the dispatched
     * Livewire event back at this component. That only works if the wizard
     * is discoverable under its own class name; `RegistrationWizardFeature`
     * registers it under the short alias `tenant-registration` instead (via
     * `Livewire::addComponent`), same as `company-info`/`technical-setup`/
     * `plan`. Left uncorrected, `.to()` targets a component name nothing is
     * embedded under, so the dispatched event has nowhere to land —
     * `nextStep`/`previousStep`/`showStep` become silent no-ops: no
     * exception, no validation error, the request round-trips successfully,
     * and the wizard simply never advances. Confirmed live, 2026-08-12:
     * clicking "Continue" (and calling `$wire.continue()` directly) on the
     * first step never changed `currentStepName`, with nothing in the logs.
     * Same fix shape `stateToPersist()` below already uses for `Plan`'s own
     * alias (`livewire.finder`, not a hardcoded string) — resolved here
     * rather than reintroducing the exact class-name assumption that broke.
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function getCurrentStepState(?string $step = null): array
    {
        return [
            ...parent::getCurrentStepState($step),
            'wizardClassName' => resolve('livewire.finder')->normalizeName(static::class),
        ];
    }

    /**
     * Mirrors `numerosis.tenancy.provisioning.steps`'s nesting deliberately —
     * see that key's docblock in `config/numerosis.php`.
     *
     * @return list<class-string<Component>>
     */
    public function steps(): array
    {
        /** @var list<class-string<Component>> $steps */
        $steps = Config::array('numerosis.tenancy.registration.steps');

        return $steps;
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
     * The wizard state worth persisting: everything except Stripe secrets and
     * request-local UI flags. Checkout re-derives those from the pending
     * provision row, so a session copy would only ever be stale — and one
     * fewer thing in the session is one fewer thing to tamper with.
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
        $index = $this->currentStepIndex();

        if ($index !== false) {
            return $this->getFormalStepNameFor($index + 1);
        }

        return $this->humanize((string) $this->currentStepName);
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

        return $this->humanize($slug);
    }

    public function getCurrentStepNumber(): int
    {
        $index = $this->currentStepIndex();

        return $index !== false ? $index + 1 : 1;
    }

    private function currentStepIndex(): int|false
    {
        $index = $this->stepNames()->search(fn (string $step) => $step === $this->currentStepName);

        return is_int($index) ? $index : false;
    }

    private function humanize(string $slug): string
    {
        return ucwords(str_replace('-', ' ', $slug));
    }
}
