<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Livewire\Tenant\Registration;

use RuntimeException;

/**
 * Narrows `StepComponent::state()` to the wizard's own state class, which is
 * where the provisioning payload is assembled. The parent returns the base
 * `State`, so a step reaching for `provisionData()` would not type-check.
 */
trait ReadsRegistrationState
{
    protected function registrationState(): RegistrationState
    {
        $state = $this->state();

        if (! $state instanceof RegistrationState) {
            throw new RuntimeException('The registration wizard must use '.RegistrationState::class.'.');
        }

        return $state;
    }
}
