<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Features\Tenancy;

use Illuminate\Support\Facades\Config;
use Livewire\Livewire;
use Nvade\Numerosis\Boot\ConfiguredSteps;
use Nvade\Numerosis\Contracts\NamedFeature;
use Nvade\Numerosis\Enums\Tenancy\WizardStep;
use Nvade\Numerosis\Features\Concerns\IsNamedFeature;
use Nvade\Numerosis\Livewire\Tenant\Registration as WizardRegistration;

/**
 * The self-serve tenant registration wizard and its route.
 *
 * Remove it from `numerosis.features` and there is no self-serve signup.
 * Provisioning itself is unaffected, since the wizard is only one caller of
 * it, so creating tenants from an admin screen or a job keeps working.
 */
class RegistrationWizardFeature implements NamedFeature
{
    use IsNamedFeature;

    public const NAME = 'registration_wizard';

    public function bootstrap(): void
    {
        // Fills an unset key only, so a host's own step list wins. Every
        // feature's bootstrap() runs after config publishing and a host
        // provider's register(), so there is no order race to guard.
        if (Config::get('numerosis.tenancy.registration.steps') === null) {
            Config::set(
                'numerosis.tenancy.registration.steps',
                array_map(fn (WizardStep $step): string => $step->componentClass(), WizardStep::cases()),
            );
        }

        // Livewire::addComponent() takes an absolute path, so it is built
        // from `numerosis.views.path`, which
        // NumerosisServiceProvider::packageBooted() sets.
        $wizardViews = Config::string('numerosis.views.path').'/livewire/tenant/registration/wizard';

        $steps = ConfiguredSteps::registrationSteps();

        if (app()->runningInConsole()) {
            ConfiguredSteps::assertARegistrationStepProvidesTenantIdentity($steps);
        }

        Livewire::addComponent(
            name: 'tenant-registration',
            viewPath: $wizardViews.'/index.blade.php',
            class: WizardRegistration::class,
        );

        foreach ($steps as $step) {
            $alias = WizardStep::fromComponentClass($step)?->alias();

            if ($alias === null) {
                continue;
            }

            Livewire::addComponent(
                name: $alias,
                viewPath: $wizardViews.'/steps/'.$alias.'.blade.php',
                class: $step,
            );
        }
    }
}
