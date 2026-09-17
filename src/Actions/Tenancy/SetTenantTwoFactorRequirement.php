<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Illuminate\Support\Facades\Config;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Models\Central\Tenant;

/**
 * @method static void run(Tenant $tenant, bool $required)
 */
class SetTenantTwoFactorRequirement
{
    use AsAction;

    public function handle(Tenant $tenant, bool $required): void
    {
        // Turning it on a second time must not restart the clock, or an owner
        // toggling the switch keeps the team permanently inside the grace.
        if ($required && $tenant->requiresTwoFactor()) {
            return;
        }

        $tenant->forceFill([
            'requires_two_factor_from' => $required
                ? now()->addDays(Config::integer('numerosis.tenancy.two_factor.grace_days', 7))
                : null,
        ])->save();
    }
}
