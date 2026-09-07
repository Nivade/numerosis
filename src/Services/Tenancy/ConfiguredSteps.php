<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Services\Tenancy;

use LogicException;
use Nvade\Numerosis\Contracts\Tenancy\CreatesTenant;
use Nvade\Numerosis\Contracts\Tenancy\ProvidesTenantIdentity;

/**
 * Shape checks for the two host-editable step lists, `numerosis.tenancy`'s
 * `provisioning.steps` and `registration.steps`. Both fail far from the config
 * that caused them otherwise: inside a queued job on its fifth retry, or on
 * whichever wizard screen first asks for an identifier that was never
 * collected.
 *
 * Callers run these on console boots only. Neither answer can change between
 * requests, and the registration one force-autoloads every configured Livewire
 * step on requests that never reach registration. A queue worker and
 * `numerosis:install` are both console boots, so a misconfigured list is still
 * refused before anything provisions.
 */
final class ConfiguredSteps
{
    /**
     * @param  list<class-string>  $steps
     */
    public static function assertTheFirstProvisioningStepCreatesTenant(array $steps): void
    {
        if ($steps === []) {
            throw new LogicException('numerosis.tenancy.provisioning.steps is empty; the first entry has to create the tenant.');
        }

        if (is_a($steps[0], CreatesTenant::class, true)) {
            return;
        }

        throw new LogicException(
            'The first entry in numerosis.tenancy.provisioning.steps must implement '
            .CreatesTenant::class.'. It runs synchronously as run($registration): Tenant, '
            .'unlike every later step, which the queue calls as run($tenant, $data): void. '
            ."[{$steps[0]}] does not.",
        );
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
