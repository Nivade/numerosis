<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Tenancy;

/**
 * A registration wizard step that collects something provisioning will need.
 *
 * Takes the step's own dehydrated state instead of the live component. The
 * step that starts checkout is a different instance from the ones that
 * collected the data, which by then exist only as wizard state.
 */
interface ContributesProvisionData
{
    /**
     * @param  array<string, mixed>  $state  This step's own wizard state.
     * @return ProvisionContribution|null Null when the step collected nothing
     *                                    to contribute.
     */
    public static function contribute(array $state): ?ProvisionContribution;
}
