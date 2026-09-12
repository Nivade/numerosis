<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Tenancy;

/**
 * A registration wizard step contributing part of the tenant's identity into
 * the wizard's shared state. `Boot\ConfiguredSteps` fails the boot
 * when no step in `numerosis.tenancy.registration.steps` implements this,
 * since such a list still renders and surfaces as a blank name or domain
 * inside the queued `ProvisionTenant` chain.
 */
interface ProvidesTenantIdentity
{
    /**
     * Wizard-state field name(s) this step writes.
     *
     * `RegistrationState::provisionData()` reads these to know which step to
     * send the user back to when the identity is not collected yet. Until
     * then nothing called this method, so a step could return anything.
     *
     * Static because it is asked of a step class the wizard is not currently
     * rendering, which has no instance.
     *
     * @return list<string>
     */
    public static function tenantIdentityStateKeys(): array;
}
