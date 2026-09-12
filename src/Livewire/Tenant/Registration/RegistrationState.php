<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Livewire\Tenant\Registration;

use Illuminate\Support\Fluent;
use Nvade\Numerosis\Enums\Billing\BillingCycle;
use Nvade\Numerosis\Livewire\Tenant\Registration\Steps\CompanyInfo;
use Nvade\Numerosis\Livewire\Tenant\Registration\Steps\Plan;
use Nvade\Numerosis\Livewire\Tenant\Registration\Steps\TechnicalSetup;
use Override;
use Spatie\LivewireWizard\Support\State;

class RegistrationState extends State
{
    /**
     * @return array<string, mixed>
     */
    public function paymentPlan(): array
    {
        $state = $this->forStepClass(Plan::class);

        // Reading the step's own live state gives the enum; reading another
        // step's gives what `StepComponent::dispatchDehydrated()` wrote, a
        // string. Callers get the string either way.
        $cycle = $state['billingCycle'] ?? null;

        return [
            'payment_plan' => $state['payment_plan'] ?? null,
            'billing_cycle' => $cycle instanceof BillingCycle ? $cycle->value : $cycle,
            'terms' => $state['terms'] ?? null,
            'is_submitting' => $state['isSubmitting'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function checkout(): array
    {
        $state = $this->forStepClass(Plan::class);

        return [
            'checkout_client_secret' => $state['checkoutClientSecret'] ?? null,
            'checkout_publishable_key' => $state['checkoutPublishableKey'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function technical(): array
    {
        $state = $this->forStepClass(TechnicalSetup::class);

        return [
            'domain' => $state['domain'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function companyInfo(): array
    {
        $state = $this->forStepClass(CompanyInfo::class);

        return [
            'name' => $state['name'] ?? null,
            'admin_email' => $state['admin_email'] ?? null,
        ];
    }

    #[Override]
    public function get(string $key): mixed
    {
        $arr = Fluent::make(
            array_merge(
                $this->companyInfo(),
                $this->technical(),
                $this->paymentPlan(),
                $this->checkout(),
            )
        );

        return $arr->get($key);
    }
}
