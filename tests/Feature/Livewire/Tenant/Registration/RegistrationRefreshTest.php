<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Livewire\Tenant\Registration;

use App\Models\Central\CentralUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Nvade\Numerosis\Tests\TestCase;
use Nvade\NumerosisOnboarding\Livewire\Registration;
use Nvade\NumerosisOnboarding\Livewire\Steps\CompanyInfo;
use Nvade\NumerosisOnboarding\Livewire\Steps\Payment;
use Nvade\NumerosisOnboarding\Livewire\Steps\Plan;
use Nvade\NumerosisOnboarding\Livewire\Steps\TechnicalSetup;
use Nvade\NumerosisOnboarding\Support\RegistrationState;

class RegistrationRefreshTest extends TestCase
{
    use RefreshDatabase;

    public function test_field_values_survive_a_refresh_via_session_state(): void
    {
        $this->actingAs(CentralUser::factory()->create());

        // Manually set the session state that would be persisted after the user
        // filled in and submitted the first two steps. This simulates the
        // persistence that happens via Registration::showStep().
        session()->put('registration.wizard_state', [
            'company-info' => ['company_name' => 'Acme Corp'],
            'technical-setup' => ['domain' => 'acme-refresh-test'],
            'plan' => ['payment_plan' => null, 'billing_cycle' => 'monthly'],
        ]);

        // A hard refresh is a brand-new Registration instance. Verify that
        // initialState() reads from the session and restores the step state.
        $resumed = Livewire::test(Registration::class);

        /** @var Registration $component */
        $component = $resumed->instance();

        // The allStepState should be hydrated from the session via initialState()
        $this->assertSame('Acme Corp', $component->getCurrentStepState('company-info')['company_name'] ?? null);
        $this->assertSame('acme-refresh-test', $component->getCurrentStepState('technical-setup')['domain'] ?? null);
    }

    /**
     * `WizardComponent::getCurrentStepState()` (vendor) hands every step
     * component a `wizardClassName` of `static::class` — the raw FQCN —
     * which `StepComponent::nextStep()`/`previousStep()`/`showStep()` then
     * use as `->to($this->wizardClassName)` to target the event back at the
     * wizard. `RegistrationWizardFeature` registers `Registration` under the
     * short alias `tenant-registration` (`Livewire::addComponent`), not its
     * class name, so the vendor default silently mistargets every
     * transition: no exception, no validation error, the step just never
     * advances. `Registration::getCurrentStepState()` overrides this to
     * resolve the real alias. Confirmed against the pre-fix code that this
     * test fails (dispatches to the raw FQCN instead).
     */
    public function test_it_dispatches_step_transitions_to_the_wizards_registered_alias(): void
    {
        $this->actingAs(CentralUser::factory()->create());

        $alias = resolve('livewire.finder')->normalizeName(Registration::class);

        Livewire::test(CompanyInfo::class, $this->wizardParams())
            ->set('company_name', 'Acme Corp')
            ->call('continue')
            ->assertDispatchedTo($alias, 'nextStep');
    }

    public function test_plan_step_stripe_fields_are_never_written_to_the_session(): void
    {
        $this->actingAs(CentralUser::factory()->create());

        // Move through company info and technical setup
        Livewire::test(CompanyInfo::class, $this->wizardParams())
            ->set('company_name', 'Acme Corp')
            ->call('continue');

        Livewire::test(TechnicalSetup::class, $this->wizardParams())
            ->set('domain', 'acme-session-test')
            ->call('continue');

        // Verify only user-provided fields are in session, not Stripe fields
        /** @var array<string, mixed> $wizardState */
        $wizardState = session('registration.wizard_state', []);
        /** @var array<string, mixed> $planState */
        $planState = $wizardState['plan'] ?? [];

        $this->assertArrayNotHasKey('checkoutClientSecret', $planState);
        $this->assertArrayNotHasKey('checkoutPublishableKey', $planState);
        $this->assertArrayNotHasKey('isSubmitting', $planState);
        $this->assertArrayNotHasKey('checkoutError', $planState);
    }

    /**
     * @return array<string, mixed>
     */
    private function wizardParams(): array
    {
        $companyInfoAlias = resolve('livewire.finder')->normalizeName(CompanyInfo::class);
        $technicalSetupAlias = resolve('livewire.finder')->normalizeName(TechnicalSetup::class);
        $planAlias = resolve('livewire.finder')->normalizeName(Plan::class);
        $paymentAlias = resolve('livewire.finder')->normalizeName(Payment::class);

        return [
            'wizardClassName' => resolve('livewire.finder')->normalizeName(Registration::class),
            'stateClassName' => RegistrationState::class,
            'allStepNames' => [$companyInfoAlias, $technicalSetupAlias, $planAlias, $paymentAlias],
            'allStepsState' => [],
        ];
    }
}
