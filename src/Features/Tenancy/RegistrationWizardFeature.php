<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Features\Tenancy;

use Illuminate\Support\Facades\Config;
use Livewire\Livewire;
use Nvade\Numerosis\Boot\ConfiguredSteps;
use Nvade\Numerosis\Contracts\NamedFeature;
use Nvade\Numerosis\Features\Concerns\IsNamedFeature;
use Nvade\Numerosis\Livewire\Tenant\Registration as WizardRegistration;
use Nvade\Numerosis\Livewire\Tenant\Registration\Steps as Wizard;

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

    /**
     * Written and read by the wizard, cleared by core's two checkout
     * completion paths (`CompleteRedirectCheckout`,
     * `Livewire\Billing\Checkout::settle()`).
     */
    public const SESSION_KEY = 'registration.wizard_state';

    /**
     * Alias each shipped step registers under, and the view file that alias
     * resolves to. Written out by hand, never derived: `Steps\Payment` is
     * absent because its natural alias collides with Cashier's published
     * `payment.blade.php`, so it resolves by FQCN. A host-supplied step is not
     * auto-registered here; register your own component for it.
     *
     * @var array<class-string, string>
     */
    private const SHIPPED_STEP_ALIASES = [
        Wizard\CompanyInfo::class => 'company-info',
        Wizard\TechnicalSetup::class => 'technical-setup',
        Wizard\Plan::class => 'plan',
    ];

    public function bootstrap(): void
    {
        // Fills an unset key only, so a host's own step list wins. Every
        // feature's bootstrap() runs after config publishing and a host
        // provider's register(), so there is no order race to guard.
        if (Config::get('numerosis.tenancy.registration.steps') === null) {
            Config::set('numerosis.tenancy.registration.steps', [
                Wizard\CompanyInfo::class,
                Wizard\TechnicalSetup::class,
                Wizard\Plan::class,
                Wizard\Payment::class,
            ]);
        }

        // Livewire::addComponent() takes an absolute path, so it is built
        // from `numerosis.views.path`, which
        // NumerosisServiceProvider::packageBooted() sets.
        $wizardViews = Config::string('numerosis.views.path').'/livewire/tenant/registration/wizard';

        /** @var list<class-string> $steps */
        $steps = Config::array('numerosis.tenancy.registration.steps');

        if (app()->runningInConsole()) {
            ConfiguredSteps::assertARegistrationStepProvidesTenantIdentity($steps);
        }

        Livewire::addComponent(
            name: 'tenant-registration',
            viewPath: $wizardViews.'/index.blade.php',
            class: WizardRegistration::class,
        );

        foreach ($steps as $step) {
            if (! isset(self::SHIPPED_STEP_ALIASES[$step])) {
                continue;
            }

            $alias = self::SHIPPED_STEP_ALIASES[$step];

            Livewire::addComponent(
                name: $alias,
                viewPath: $wizardViews.'/steps/'.$alias.'.blade.php',
                class: $step,
            );
        }
    }
}
