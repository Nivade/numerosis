<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Livewire\Tenant\Registration\Steps;

use App\Models\Central\CentralUser;
use App\Models\Central\PaymentPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Nvade\Numerosis\Livewire\Tenant\Registration\Registration;
use Nvade\Numerosis\Livewire\Tenant\Registration\Steps\Payment;
use Nvade\Numerosis\Support\State\RegistrationState;
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
     * @return \Livewire\Features\SupportTesting\Testable<Payment>
     */
    private function paymentStep(?string $domain, ?string $paymentPlan = null)
    {
        $paymentAlias = app('livewire.finder')->normalizeName(Payment::class);

        return Livewire::test(Payment::class, [
            'wizardClassName' => Registration::class,
            'stateClassName' => RegistrationState::class,
            'allStepNames' => ['company-info', 'technical-setup', 'plan', $paymentAlias],
            'allStepsState' => [
                'technical-setup' => ['domain' => $domain],
                'plan' => ['payment_plan' => $paymentPlan, 'billing_cycle' => 'monthly'],
            ],
        ]);
    }
}
