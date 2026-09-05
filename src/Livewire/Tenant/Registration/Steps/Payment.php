<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Livewire\Tenant\Registration\Steps;

use Illuminate\View\View;
use Nvade\Numerosis\Enums\Billing\BillingCycle;
use Nvade\Numerosis\Models\Central\PaymentPlan;
use Nvade\Numerosis\Support\Numerosis;
use Spatie\LivewireWizard\Components\StepComponent;

class Payment extends StepComponent
{
    public function mount(): void
    {
        if (blank($this->state()->get('domain'))) {
            $this->showStep('plan');
        }
    }

    public function back(): void
    {
        $this->previousStep();
    }

    public function render(): View
    {
        $paymentPlanSlug = $this->state()->get('payment_plan');
        $billingCycle = $this->state()->get('billing_cycle');

        return view('numerosis::livewire.tenant.registration.wizard.steps.payment', [
            'plan' => is_string($paymentPlanSlug) ? Numerosis::model(PaymentPlan::class)::available()->where('slug', $paymentPlanSlug)->first() : null,
            'billingCycle' => is_string($billingCycle) ? BillingCycle::from($billingCycle) : BillingCycle::Monthly,
            'domain' => $this->state()->get('domain'),
            'customDomain' => $this->state()->get('customDomain'),
        ]);
    }
}
