<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Tenancy;

/**
 * A registration wizard step that contributes part of the tenant's identity
 * — its display name, its subdomain/domain, or both — into the wizard's
 * shared state.
 *
 * `RegistrationWizardFeature::bootstrap()` asserts at least one step in
 * `numerosis.tenancy.registration.steps` implements this, and fails loudly
 * at boot if none do — a step list with no identity source still lets the
 * wizard render, and would otherwise surface as a missing/blank tenant name
 * or domain deep inside the queued `ProvisionTenant` chain instead.
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
