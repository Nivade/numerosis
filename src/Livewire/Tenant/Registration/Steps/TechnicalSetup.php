<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Livewire\Tenant\Registration\Steps;

use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Nvade\Numerosis\Actions\Queries\GetAuthenticatedUser;
use Nvade\Numerosis\Actions\Tenancy\ReserveTenantDomain;
use Nvade\Numerosis\Data\Tenancy\TenantRegistrationData;
use Nvade\Numerosis\Exceptions\ShowsMessageToUser;
use Nvade\Numerosis\Rules\DomainIsAvailable;
use Spatie\LivewireWizard\Components\StepComponent;

class TechnicalSetup extends StepComponent
{
    public string $domain = '';

    /**
     * wire:model.blur.live only syncs the value to the server; it does not
     * validate. Without this, a reserved/taken domain shows no feedback
     * until Continue is clicked.
     */
    public function updatedDomain(): void
    {
        $this->validateOnly('domain');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $user = GetAuthenticatedUser::run();

        return [
            'domain' => [
                'required',
                'string',
                new DomainIsAvailable,
                // Excludes the current user's own reservation so re-submitting
                // this step (e.g. Back then Continue again) doesn't self-block
                // on the row this same action creates below. See
                // Nvade\Numerosis\Actions\Tenancy\ReserveTenantDomain.
                Rule::unique('pending_tenant_provisions', 'domain')
                    ->where(fn ($query) => $query->where('global_id', '!=', $user?->global_id)),
            ],
        ];
    }

    /**
     * Reserves the domain here rather than at checkout, so a domain someone
     * else claims mid-wizard is caught before the user picks a plan and
     * starts paying. Checkout reserves it again, harmlessly.
     */
    public function continue(): void
    {
        $this->validate();

        $companyName = $this->state()->get('company_name');

        if (blank($companyName)) {
            $this->showStep('company-info');

            return;
        }

        $user = GetAuthenticatedUser::run();

        abort_if($user === null, 403);

        try {
            ReserveTenantDomain::run(new TenantRegistrationData(
                company_name: (string) $companyName,
                domain: $this->domain,
                global_id: $user->global_id,
            ));
        } catch (ShowsMessageToUser $e) {
            $this->addError('domain', $e->getMessage());

            return;
        }

        $this->nextStep();
    }

    public function back(): void
    {
        $this->previousStep();
    }

    public function render(): View
    {
        return view('numerosis::livewire.tenant.registration.wizard.steps.technical-setup');
    }
}
