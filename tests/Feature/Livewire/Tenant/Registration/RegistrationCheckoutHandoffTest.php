<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Livewire\Tenant\Registration;

use App\Models\Central\CentralUser;
use App\Models\Central\PaymentPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Nvade\Numerosis\Livewire\Tenant\Registration;
use Nvade\Numerosis\Livewire\Tenant\Registration\Steps\Plan;
use Nvade\Numerosis\Support\Tenancy\RegistrationState;
use Nvade\Numerosis\Testing\FakesStripe;
use Nvade\Numerosis\Tests\TestCase;

class RegistrationCheckoutHandoffTest extends TestCase
{
    use FakesStripe;
    use RefreshDatabase;

    public function test_the_wizard_starts_on_the_first_step(): void
    {
        $this->actingAs(CentralUser::factory()->create());

        Livewire::test(Registration::class)
            ->assertSet('currentStepName', 'company-info');
    }

    public function test_it_returns_to_company_info_when_the_company_name_is_missing(): void
    {
        $this->actingAs(CentralUser::factory()->create());
        $this->planStep(['technical-setup' => ['domain' => 'acme']])
            ->set('payment_plan', 'starter')
            ->set('terms', true)
            ->call('register')
            ->assertNoRedirect()
            ->assertDispatchedTo(Registration::class, 'showStep', toStepName: 'company-info');
    }

    public function test_it_returns_to_technical_setup_when_the_domain_is_missing(): void
    {
        $this->actingAs(CentralUser::factory()->create());
        $this->planStep(['company-info' => ['company_name' => 'Acme']])
            ->set('payment_plan', 'starter')
            ->set('terms', true)
            ->call('register')
            ->assertNoRedirect()
            ->assertDispatchedTo(Registration::class, 'showStep', toStepName: 'technical-setup');
    }

    /**
     * Plan now starts the checkout itself (rather than redirecting to the
     * checkout.subscription route) and hands the resulting InlineCheckout
     * intent to the Payment step via RegistrationState — see
     * custom-checkout.md's target flow, step 1. The customer and SetupIntent
     * creation this drives go through FakeStripeHttpClient (D9), not the live
     * API: with a dummy key the real one answers `Invalid API Key provided`
     * from inside the SDK.
     */
    public function test_it_starts_an_inline_checkout_with_the_full_wizard_state(): void
    {
        $this->fakeStripe();

        $user = CentralUser::factory()->create();
        $this->actingAs($user);

        PaymentPlan::factory()->create([
            'slug' => 'starter',
            'monthly_id' => 'price_test_monthly',
            'yearly_id' => 'price_test_yearly',
            'trial_days' => 0,
        ]);

        $this->planStep([
            'company-info' => ['company_name' => 'Acme'],
            'technical-setup' => ['domain' => 'acme'],
        ])
            ->set('payment_plan', 'starter')
            ->set('terms', true)
            ->call('register')
            ->assertSet('checkoutClientSecret', fn (?string $value) => is_string($value) && str_starts_with($value, 'seti_'))
            ->assertSet('checkoutPublishableKey', fn (?string $value) => ! in_array($value, [null, '', '0'], true))
            ->assertDispatchedTo(Registration::class, 'nextStep');
    }

    /**
     * StartSubscriptionCheckout's refusals are DomainExceptions whose
     * messages are written as customer copy. Uncaught they render as a 500
     * and the user sees a dead button, which is what a retired plan slug —
     * reachable because Plan only validates payment_plan as a string —
     * used to produce.
     */
    public function test_a_refused_checkout_is_shown_to_the_user_rather_than_thrown(): void
    {
        $this->actingAs(CentralUser::factory()->create());

        PaymentPlan::factory()->create(['slug' => 'legacy-cheap', 'available' => false]);

        $this->planStep([
            'company-info' => ['company_name' => 'Acme'],
            'technical-setup' => ['domain' => 'acme'],
        ])
            ->set('payment_plan', 'legacy-cheap')
            ->set('terms', true)
            ->call('register')
            ->assertSet('checkoutError', fn (?string $value): bool => filled($value))
            ->assertNoRedirect();
    }

    /**
     * @param  array<string, array<string, mixed>>  $stepsState
     */
    private function planStep(array $stepsState): Testable
    {
        return Livewire::test(Plan::class, [
            'wizardClassName' => resolve('livewire.finder')->normalizeName(Registration::class),
            'stateClassName' => RegistrationState::class,
            'allStepNames' => ['company-info', 'technical-setup', 'plan'],
            'allStepsState' => $stepsState,
        ]);
    }
}
