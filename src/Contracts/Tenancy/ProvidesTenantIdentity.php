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
    /** The wizard-state key carrying the tenant's display name. */
    public const string NAME_KEY = 'name';

    /** The wizard-state key carrying the tenant's slug. */
    public const string SLUG_KEY = 'domain';

    /**
     * Wizard-state field name(s) this step writes. The configured steps
     * together must cover {@see self::NAME_KEY} and {@see self::SLUG_KEY};
     * `Boot\ConfiguredSteps` refuses the boot otherwise.
     *
     * Static, because it is asked of a step class the wizard is not currently
     * rendering, which has no instance.
     *
     * @return list<string>
     */
    public static function tenantIdentityStateKeys(): array;
}
