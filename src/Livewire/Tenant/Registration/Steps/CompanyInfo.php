<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Livewire\Tenant\Registration\Steps;

use Illuminate\Contracts\View\View;
use Nvade\Numerosis\Contracts\Tenancy\ProvidesTenantIdentity;
use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;
use Override;
use Spatie\LivewireWizard\Components\StepComponent;

class CompanyInfo extends StepComponent implements ProvidesTenantIdentity
{
    public string $name = '';

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return TenantProvisionData::rules();
    }

    public function continue(): void
    {
        $this->validate();

        $this->nextStep();
    }

    #[Override]
    public static function tenantIdentityStateKeys(): array
    {
        return ['name'];
    }

    public function render(): View
    {
        return view('numerosis::livewire.tenant.registration.wizard.steps.company-info');
    }
}
