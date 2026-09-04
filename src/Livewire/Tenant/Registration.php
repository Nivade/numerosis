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
     * Redeclared from `WizardComponent` so `#[Url]` can attach to it, which
     * is what makes a hard refresh land on the step the query string names.
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
     * Resolves `wizardClassName` to the alias this component is registered
     * under. The parent sets it to `static::class`, which every step's
     * `->to($this->wizardClassName)` then targets, so leaving it makes each
     * transition a silent no-op.
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
     * Mirrors `numerosis.tenancy.provisioning.steps`'s nesting deliberately;
     * that key's own docblock in `config/numerosis.php` says why.
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
     * Restores the steps already left behind, which {@see self::showStep()}
     * wrote. The open step is not in there until it is submitted.
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
     * The one choke point every step transition passes through, so wizard
     * state is persisted here and never in a step component. Keep
     * `#[On('showStep')]` on this override; attributes do not inherit, and
     * the event is how `StepComponent::showStep()` reaches it.
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
     * provision row, so a session copy would only ever be stale, and one fewer
     * thing in the session is one fewer thing to tamper with.
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
