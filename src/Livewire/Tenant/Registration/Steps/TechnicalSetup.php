<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Livewire\Tenant\Registration\Steps;

use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Nvade\Numerosis\Actions\Queries\GetAuthenticatedUser;
use Nvade\Numerosis\Actions\Tenancy\ReserveTenantDomain;
use Nvade\Numerosis\Contracts\Tenancy\ContributesProvisionData;
use Nvade\Numerosis\Contracts\Tenancy\ProvidesTenantIdentity;
use Nvade\Numerosis\Contracts\Tenancy\ProvisionContribution;
use Nvade\Numerosis\Data\Tenancy\CustomDomainContribution;
use Nvade\Numerosis\Enums\Tenancy\IdentificationMode;
use Nvade\Numerosis\Exceptions\ShowsMessageToUser;
use Nvade\Numerosis\Exceptions\Tenancy\MissingTenantIdentity;
use Nvade\Numerosis\Livewire\Tenant\Registration\ReadsRegistrationState;
use Nvade\Numerosis\Rules\CustomDomainIsAvailable;
use Nvade\Numerosis\Rules\DomainIsAvailable;
use Override;
use Spatie\LivewireWizard\Components\StepComponent;

class TechnicalSetup extends StepComponent implements ContributesProvisionData, ProvidesTenantIdentity
{
    use ReadsRegistrationState;

    /**
     * Always the tenant's safe id/slug, whatever the mode.
     *
     * @see \Nvade\Numerosis\Actions\Tenancy\CreateTenantDomain
     */
    public string $domain = '';

    /**
     * Only used under IdentificationMode::CustomDomain.
     *
     * @see IdentificationMode::current()
     */
    public string $customDomain = '';

    /**
     * Only under IdentificationMode::CustomDomain; otherwise the tenant has no
     * domain of its own to contribute.
     */
    public static function contribute(array $state): ?ProvisionContribution
    {
        $customDomain = $state['customDomain'] ?? null;

        return is_string($customDomain) && $customDomain !== ''
            ? new CustomDomainContribution($customDomain)
            : null;
    }

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
                $this->unreservedByAnyoneElse('slug', $user?->global_id),
            ],
        ];

        if (IdentificationMode::current() === IdentificationMode::CustomDomain) {
            $rules['customDomain'] = [
                'required',
                'string',
                new CustomDomainIsAvailable,
                $this->unreservedByAnyoneElse('custom_domain', $user?->global_id),
            ];
        }

        return $rules;
    }

    /**
     * Excludes the caller's own reservation, so re-submitting this step does
     * not self-block on the row {@see ReserveTenantDomain} already created.
     */
    private function unreservedByAnyoneElse(string $column, ?string $globalId): Unique
    {
        return Rule::unique('tenant_provisions', $column)
            ->where(fn ($query) => $query->where('global_id', '!=', $globalId));
    }

    /**
     * Reserves the domain here, well before checkout, so a domain someone else
     * claims mid-wizard is caught before the user picks a plan and starts
     * paying. Checkout reserves it again, harmlessly.
     */
    public function continue(): void
    {
        $this->validate();

        $user = GetAuthenticatedUser::run();

        abort_if($user === null, 403);

        try {
            // This step's own values are not in the wizard state yet -- state
            // is written on submit -- so the slug and its contribution are
            // passed in rather than collected.
            $data = $this->registrationState()
                ->provisionData($user->global_id, slug: $this->domain)
                ->withContributions(array_filter([self::contribute(['customDomain' => $this->customDomain])]));

            ReserveTenantDomain::run($data);
        } catch (MissingTenantIdentity $e) {
            $this->showStep($e->step);

            return;
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
