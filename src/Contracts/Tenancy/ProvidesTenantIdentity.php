<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Tenancy;

/**
 * A registration wizard step contributing part of the tenant's identity, its
 * display name or its domain, into the wizard's shared state.
 * `RegistrationWizardFeature::bootstrap()` fails at boot when no step in
 * `numerosis.tenancy.registration.steps` implements this, since such a list
 * still renders and surfaces as a blank name or domain inside the queued
 * `ProvisionTenant` chain.
 */
interface ProvidesTenantIdentity
{
    /**
     * Wizard-state field name(s) this step writes, read via
     * `$this->state()->get($key)` by whatever builds
     * `Nvade\Numerosis\Data\Tenancy\TenantRegistrationData` from the
     * wizard's accumulated state (currently `TechnicalSetup::continue()`
     * and `Plan::continue()`, by hand).
     *
     * @return list<string>
     */
    public function tenantIdentityStateKeys(): array;
}
