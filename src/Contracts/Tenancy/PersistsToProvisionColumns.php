<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Tenancy;

use Nvade\Numerosis\Models\Central\TenantProvision;

/**
 * A contribution stored in real columns rather than the `contributions` JSON
 * blob, for fields something has to query: `ResolveSetupIntent` looks a
 * provision up by `stripe_setup_intent_id`, the Stripe webhook filters on
 * `stripe_subscription_id`, and the wizard's uniqueness rule checks
 * `custom_domain`.
 */
interface PersistsToProvisionColumns extends ProvisionContribution
{
    /**
     * Null when the row carries nothing for this contribution.
     */
    public static function fromProvision(TenantProvision $provision): ?static;

    /**
     * @return array<string, mixed> Column values to write onto the row.
     */
    public function toProvisionColumns(): array;
}
