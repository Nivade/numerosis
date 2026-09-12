<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Livewire\Tenant\Registration;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Fluent;
use Nvade\Numerosis\Contracts\Tenancy\ContributesProvisionData;
use Nvade\Numerosis\Contracts\Tenancy\ProvisionContribution;
use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;
use Nvade\Numerosis\Enums\Billing\BillingCycle;
use Nvade\Numerosis\Exceptions\Tenancy\MissingTenantIdentity;
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

    /**
     * The payload provisioning takes, built once here rather than field by
     * field in whichever step happens to be submitting.
     *
     * Contributions come from every configured step that offers one, so a
     * host step reaches provisioning through the same seam core's own steps
     * use.
     *
     * @throws MissingTenantIdentity When the wizard has no name or slug yet,
     *                               which means a step was skipped.
     */
    public function provisionData(string $globalId): TenantProvisionData
    {
        $name = $this->get('name');
        $slug = $this->get('domain');

        if (! is_string($name) || $name === '' || ! is_string($slug) || $slug === '') {
            throw new MissingTenantIdentity;
        }

        return new TenantProvisionData(
            slug: $slug,
            name: $name,
            global_id: $globalId,
            contributions: $this->contributions(),
        );
    }

    /**
     * @return list<ProvisionContribution>
     */
    public function contributions(): array
    {
        /** @var list<class-string> $steps */
        $steps = Config::array('numerosis.tenancy.registration.steps', []);

        $contributions = [];

        foreach ($steps as $step) {
            if (! is_a($step, ContributesProvisionData::class, true)) {
                continue;
            }

            $contribution = $step::contribute($this->forStepClass($step));

            if ($contribution !== null) {
                $contributions[] = $contribution;
            }
        }

        return $contributions;
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
