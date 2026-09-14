<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Boot;

use Illuminate\Support\Facades\Config;
use LogicException;
use Nvade\Numerosis\Contracts\Tenancy\ProvidesTenantIdentity;
use Nvade\Numerosis\Contracts\Tenancy\ProvisioningStep;

/**
 * Shape checks for the two host-editable step lists, `numerosis.tenancy`'s
 * `provisioning.steps` and `registration.steps`. Callers run them on console
 * boots only: the registration check force-autoloads every configured Livewire
 * step, and a queue worker is a console boot, so a bad list is still refused
 * before anything provisions.
 */
final class ConfiguredSteps
{
    /**
     * @return list<class-string<ProvisioningStep>>
     */
    public static function provisioningSteps(): array
    {
        /** @var list<class-string<ProvisioningStep>> */
        return Config::array('numerosis.tenancy.provisioning.steps', []);
    }

    /**
     * @return list<class-string>
     */
    public static function registrationSteps(): array
    {
        /** @var list<class-string> */
        return Config::array('numerosis.tenancy.registration.steps', []);
    }

    /**
     * @param  list<class-string>  $steps
     */
    public static function assertEveryProvisioningStepIsOne(array $steps): void
    {
        throw_if($steps === [], new LogicException('numerosis.tenancy.provisioning.steps is empty; nothing would provision a tenant.'));

        foreach ($steps as $step) {
            if (is_a($step, ProvisioningStep::class, true)) {
                continue;
            }

            throw new LogicException(
                'Every entry in numerosis.tenancy.provisioning.steps must implement '
                .ProvisioningStep::class.', which the queue calls as '
                ."handle(TenantProvision \$provision): void. [{$step}] does not.",
            );
        }
    }

    /**
     * @param  list<class-string>  $steps
     */
    public static function assertARegistrationStepProvidesTenantIdentity(array $steps): void
    {
        foreach ($steps as $step) {
            if (is_a($step, ProvidesTenantIdentity::class, true)) {
                return;
            }
        }

        throw new LogicException(
            'numerosis.tenancy.registration.steps must include at least one step implementing '
            .ProvidesTenantIdentity::class.', or the provisioning pipeline has no source for '
            ."the tenant's identifier or display name.",
        );
    }
}
