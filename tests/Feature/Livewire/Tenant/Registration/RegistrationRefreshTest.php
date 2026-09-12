<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Livewire\Tenant\Registration;

use App\Models\Central\CentralUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;
use Nvade\Numerosis\Exceptions\Tenancy\MissingTenantIdentity;
use Nvade\Numerosis\Livewire\Tenant\Registration;
use Nvade\Numerosis\Livewire\Tenant\Registration\RegistrationState;
use Nvade\Numerosis\Livewire\Tenant\Registration\Steps\CompanyInfo;
use Nvade\Numerosis\Livewire\Tenant\Registration\Steps\Payment;
use Nvade\Numerosis\Livewire\Tenant\Registration\Steps\Plan;
use Nvade\Numerosis\Livewire\Tenant\Registration\Steps\TechnicalSetup;
use Nvade\Numerosis\Tests\Support\HostSecretStep;
use Nvade\Numerosis\Tests\Support\SeatCountContribution;
use Nvade\Numerosis\Tests\TestCase;
use ReflectionMethod;

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
            'company-info' => ['name' => 'Acme Corp'],
            'technical-setup' => ['domain' => 'acme-refresh-test'],
            'plan' => ['payment_plan' => null, 'billing_cycle' => 'monthly'],
        ]);

        // A hard refresh is a brand-new Registration instance. Verify that
        // initialState() reads from the session and restores the step state.
        $resumed = Livewire::test(Registration::class);

        /** @var Registration $component */
        $component = $resumed->instance();

        // The allStepState should be hydrated from the session via initialState()
        $this->assertSame('Acme Corp', $component->getCurrentStepState('company-info')['name'] ?? null);
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
            ->set('name', 'Acme Corp')
            ->call('continue')
            ->assertDispatchedTo($alias, 'nextStep');
    }

    public function test_plan_step_stripe_fields_are_never_written_to_the_session(): void
    {
        $this->actingAs(CentralUser::factory()->create());

        // Move through company info and technical setup
        Livewire::test(CompanyInfo::class, $this->wizardParams())
            ->set('name', 'Acme Corp')
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
     * The parent asks each configured step which of its own state is
     * transient, so a host step gets the same treatment as the shipped one.
     * Against the old code — five `Plan` property names hardcoded in the
     * parent — `hostSecret` reached the session.
     */
    public function test_a_host_step_declares_its_own_transient_state(): void
    {
        Livewire::component('host-secret-step', HostSecretStep::class);

        Config::set('numerosis.tenancy.registration.steps', [
            CompanyInfo::class,
            TechnicalSetup::class,
            HostSecretStep::class,
        ]);

        $wizard = new Registration;
        $wizard->allStepState = [
            'host-secret-step' => ['keep_me' => 'kept', 'hostSecret' => 'sk_live_do_not_persist'],
        ];

        $persist = new ReflectionMethod($wizard, 'stateToPersist');

        /** @var array<string, array<string, mixed>> $state */
        $state = $persist->invoke($wizard);

        $this->assertSame(['keep_me' => 'kept'], $state['host-secret-step']);
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

    /**
     * The wizard used to hand-build the provisioning payload field by field in
     * two of its own steps, so a host could collect data and have it dropped
     * on the way to provisioning with nothing to notice.
     */
    public function test_a_host_step_contributes_its_own_data_to_provisioning(): void
    {
        Livewire::component('host-secret-step', HostSecretStep::class);

        Config::set('numerosis.tenancy.registration.steps', [
            CompanyInfo::class,
            TechnicalSetup::class,
            HostSecretStep::class,
        ]);

        $state = new RegistrationState;
        $state->setAllState([
            'company-info' => ['name' => 'Acme Corp'],
            'technical-setup' => ['domain' => 'acme'],
            'host-secret-step' => ['seats' => 12],
        ]);

        $data = $state->provisionData('global-1');

        $this->assertSame('acme', $data->slug);
        $this->assertSame('Acme Corp', $data->name);
        $this->assertSame(12, $data->contribution(SeatCountContribution::class)?->seats);
    }

    /**
     * The step to return to is derived from `tenantIdentityStateKeys()`, which
     * until now was implemented and never called -- a step could return
     * anything and nothing would notice.
     */
    public function test_it_names_the_step_that_collects_the_missing_identity(): void
    {
        $state = new RegistrationState;
        $state->setAllState(['company-info' => ['name' => 'Acme Corp']]);

        try {
            $state->provisionData('global-1');
            $this->fail('A payload with no slug should have been refused.');
        } catch (MissingTenantIdentity $e) {
            $this->assertSame('technical-setup', $e->step);
        }

        $empty = new RegistrationState;
        $empty->setAllState([]);

        try {
            $empty->provisionData('global-1');
            $this->fail('A payload with no name should have been refused.');
        } catch (MissingTenantIdentity $e) {
            $this->assertSame('company-info', $e->step);
        }
    }
}
