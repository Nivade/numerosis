<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Tenancy;

/**
 * A registration wizard step that collects something provisioning will need.
 *
 * The wizard used to hand-build the provisioning payload field by field, in
 * two separate steps — `ProvidesTenantIdentity`'s own docblock said so — which
 * meant a host could add a step, collect data in it, and have that data
 * silently dropped on the way to provisioning. There was no way to get it
 * through.
 *
 * It takes the step's own dehydrated state rather than the live component
 * because the step that starts checkout is a different instance from the ones
 * that collected the data; by then the others exist only as wizard state.
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
