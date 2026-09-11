<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Livewire\Tenant\Registration\Steps;

use App\Models\Central\CentralUser;
use App\Models\Central\PaymentPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Nvade\Numerosis\Enums\Billing\BillingCycle;
use Nvade\Numerosis\Livewire\Tenant\Registration;
use Nvade\Numerosis\Livewire\Tenant\Registration\RegistrationState;
use Nvade\Numerosis\Livewire\Tenant\Registration\Steps\Payment;
use Nvade\Numerosis\Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_bounces_back_to_plan_when_no_domain_was_reserved(): void
    {
        $this->actingAs(CentralUser::factory()->create());

        $this->paymentStep(domain: null)
            ->assertDispatched('showStep', toStepName: 'plan');
    }

    public function test_it_renders_the_embedded_checkout_when_a_domain_is_known(): void
    {
        $this->actingAs(CentralUser::factory()->create());

        $plan = PaymentPlan::factory()->create(['slug' => 'starter']);

        $this->paymentStep(domain: 'payment-embed-test', paymentPlan: $plan->slug)
            ->assertNotDispatched('showStep');
    }

    /**
     * The Plan step's property is `billingCycle`, which is the key Livewire
     * dehydrates it under. `RegistrationState` read `billing_cycle` instead,
     * so the cycle reached this step as null and every order summary showed
     * monthly whatever the customer picked.
     */
    public function test_it_carries_the_billing_cycle_chosen_on_the_plan_step(): void
    {
        $this->actingAs(CentralUser::factory()->create());

        $plan = PaymentPlan::factory()->create(['slug' => 'starter']);

        $this->paymentStep(domain: 'cycle-carry-test', paymentPlan: $plan->slug, billingCycle: 'yearly')
            ->assertViewHas('billingCycle', BillingCycle::Yearly);
    }

    /**
     * @return Testable<Payment>
     */
    private function paymentStep(?string $domain, ?string $paymentPlan = null, string $billingCycle = 'monthly')
    {
        $paymentAlias = resolve('livewire.finder')->normalizeName(Payment::class);

        return Livewire::test(Payment::class, [
            'wizardClassName' => resolve('livewire.finder')->normalizeName(Registration::class),
            'stateClassName' => RegistrationState::class,
            'allStepNames' => ['company-info', 'technical-setup', 'plan', $paymentAlias],
            'allStepsState' => [
                'technical-setup' => ['domain' => $domain],
                'plan' => ['payment_plan' => $paymentPlan, 'billingCycle' => $billingCycle],
            ],
        ]);
    }
}
