<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Support\Tenancy;

use Illuminate\Support\Fluent;
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

        return [
            'payment_plan' => $state['payment_plan'] ?? null,
            'billing_cycle' => $state['billing_cycle']->value ?? null,
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
            'company_name' => $state['company_name'] ?? null,
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
