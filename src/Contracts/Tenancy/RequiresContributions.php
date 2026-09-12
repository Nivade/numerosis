<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Tenancy;

/**
 * A step that needs data someone had to contribute, and is skipped when they
 * did not.
 *
 * This is what makes a billing-dependent step ordinary rather than special.
 * `ProvisionTenant` used to splice `LinkTenantSubscription` into the chain
 * only when a subscription id was present, *and* the step re-checked the same
 * fact itself — core hardcoding one step's dependency in two places, with no
 * way for a host's own step to participate.
 *
 * The skip is recorded on the provision row alongside the steps that ran, so
 * "this step did not run, and why" is visible rather than silent.
 */
interface RequiresContributions extends ProvisioningStep
{
    /**
     * @return list<class-string<ProvisionContribution>>
     */
    public static function requires(): array;
}
