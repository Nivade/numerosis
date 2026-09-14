<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Tenancy;

/**
 * A step that needs data someone had to contribute, and is skipped when they
 * did not. The skip is recorded on the provision row alongside the steps that
 * ran, so "this step did not run, and why" is visible rather than silent.
 */
interface RequiresContributions extends ProvisioningStep
{
    /**
     * @return list<class-string<ProvisionContribution>>
     */
    public static function requires(): array;
}
