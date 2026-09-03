<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Livewire\Tenant\Registration\Steps;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Validate;
use Nvade\Numerosis\Contracts\Tenancy\ProvidesTenantIdentity;
use Override;
use Spatie\LivewireWizard\Components\StepComponent;

class CompanyInfo extends StepComponent implements ProvidesTenantIdentity
{
    #[Validate('required|string|max:255')]
    public string $company_name = '';

    public function continue(): void
    {
        $this->validate();

        $this->nextStep();
    }

    #[Override]
    public function tenantIdentityStateKeys(): array
    {
        return ['company_name'];
    }

    public function render(): View
    {
        return view('numerosis::livewire.tenant.registration.wizard.steps.company-info');
    }
}
