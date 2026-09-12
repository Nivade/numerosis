<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Tenancy;

/**
 * A step that reads a contribution without depending on it — unlike {@see RequiresContributions}, absence never skips the step.
 */
interface ReadsContributions extends ProvisioningStep
{
    /**
     * @return list<class-string<ProvisionContribution>>
     */
    public static function reads(): array;
}
