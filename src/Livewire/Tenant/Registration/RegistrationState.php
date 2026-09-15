<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Livewire\Tenant\Registration;

use Illuminate\Support\Fluent;
use LogicException;
use Nvade\Numerosis\Boot\ConfiguredSteps;
use Nvade\Numerosis\Contracts\Tenancy\ContributesProvisionData;
use Nvade\Numerosis\Contracts\Tenancy\ProvidesTenantIdentity;
use Nvade\Numerosis\Contracts\Tenancy\ProvisionContribution;
use Nvade\Numerosis\Data\Tenancy\OwnerContribution;
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
     * field in whichever step happens to be submitting. Contributions come
     * from every configured step that offers one, core's and a host's alike.
     *
     * @throws MissingTenantIdentity When the wizard has no name or slug yet,
     *                               which means a step was skipped.
     */
    public function provisionData(string $globalId, ?string $slug = null): TenantProvisionData
    {
        $name = $this->get(ProvidesTenantIdentity::NAME_KEY);

        // The step collecting the slug has not written it to wizard state yet
        // when it submits, so it passes its own value in.
        $slug ??= $this->get(ProvidesTenantIdentity::SLUG_KEY);

        if (! is_string($name) || $name === '') {
            throw new MissingTenantIdentity($this->stepProviding(ProvidesTenantIdentity::NAME_KEY));
        }

        if (! is_string($slug) || $slug === '') {
            throw new MissingTenantIdentity($this->stepProviding(ProvidesTenantIdentity::SLUG_KEY));
        }

        return new TenantProvisionData(
            slug: $slug,
            name: $name,
            contributions: [new OwnerContribution($globalId), ...$this->contributions()],
        );
    }

    /**
     * Which configured step collects a given identity field, by the name the
     * wizard knows it as. Derived, so replacing a shipped step still routes
     * the user to the right screen.
     *
     * @throws LogicException When no configured step declares the key, which
     *                        `ConfiguredSteps` refuses the boot over.
     */
    private function stepProviding(string $key): string
    {
        foreach (ConfiguredSteps::registrationSteps() as $step) {
            if (! is_a($step, ProvidesTenantIdentity::class, true)) {
                continue;
            }

            if (in_array($key, $step::tenantIdentityStateKeys(), true)) {
                // Same call Registration::getCurrentStepState() makes; the
                // trait's own componentName() is private to it.
                return (string) resolve('livewire.finder')->normalizeName($step);
            }
        }

        throw new LogicException(
            "No configured registration step declares the tenant identity key [{$key}]."
        );
    }

    /**
     * @return list<ProvisionContribution>
     */
    public function contributions(): array
    {
        $contributions = [];

        foreach (ConfiguredSteps::registrationSteps() as $step) {
            if (! is_a($step, ContributesProvisionData::class, true)) {
                continue;
            }

            $contribution = $step::contribute($this->forStepClass($step));

            if ($contribution instanceof ProvisionContribution) {
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
