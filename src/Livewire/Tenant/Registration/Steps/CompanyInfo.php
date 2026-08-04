<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Livewire\Tenant\Registration\Steps;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Validate;
use Spatie\LivewireWizard\Components\StepComponent;

class CompanyInfo extends StepComponent
{
    #[Validate('required|string|max:255')]
    public string $company_name = '';

    public function continue(): void
    {
        $this->validate();

        $this->nextStep();
    }

    public function render(): View
    {
        return view('livewire.tenant.registration.wizard.steps.company-info');
    }
}
