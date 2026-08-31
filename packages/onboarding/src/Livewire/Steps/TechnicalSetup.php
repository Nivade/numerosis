<?php

declare(strict_types=1);

namespace Nvade\NumerosisOnboarding\Livewire\Steps;

use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Nvade\Numerosis\Actions\Queries\GetAuthenticatedUser;
use Nvade\Numerosis\Actions\Tenancy\ReserveTenantDomain;
use Nvade\Numerosis\Contracts\Tenancy\ProvidesTenantIdentity;
use Nvade\Numerosis\Data\Tenancy\TenantRegistrationData;
use Nvade\Numerosis\Enums\Tenancy\IdentificationMode;
use Nvade\Numerosis\Exceptions\ShowsMessageToUser;
use Nvade\Numerosis\Rules\CustomDomainIsAvailable;
use Nvade\Numerosis\Rules\DomainIsAvailable;
use Override;
use Spatie\LivewireWizard\Components\StepComponent;

class TechnicalSetup extends StepComponent implements ProvidesTenantIdentity
{
    /**
     * Always the tenant's safe id/slug, regardless of mode — see
     * Nvade\Numerosis\Actions\Tenancy\CreateTenantDomain.
     */
    public string $domain = '';

    /**
     * Only used under IdentificationMode::CustomDomain — see
     * IdentificationMode::current().
     */
    public string $customDomain = '';

    #[Override]
    public function tenantIdentityStateKeys(): array
    {
        return IdentificationMode::current() === IdentificationMode::CustomDomain
            ? ['domain', 'customDomain']
            : ['domain'];
    }

    /**
     * wire:model.blur.live only syncs the value to the server; it does not
     * validate. Without this, a reserved/taken domain shows no feedback
     * until Continue is clicked.
     */
    public function updatedDomain(): void
    {
        $this->validateOnly('domain');
    }

    public function updatedCustomDomain(): void
    {
        $this->validateOnly('customDomain');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $user = GetAuthenticatedUser::run();

        $rules = [
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

        if (IdentificationMode::current() === IdentificationMode::CustomDomain) {
            $rules['customDomain'] = [
                'required',
                'string',
                new CustomDomainIsAvailable,
                Rule::unique('pending_tenant_provisions', 'custom_domain')
                    ->where(fn ($query) => $query->where('global_id', '!=', $user?->global_id)),
            ];
        }

        return $rules;
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
                custom_domain: $this->customDomain !== '' ? $this->customDomain : null,
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
