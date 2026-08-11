<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Features\Tenancy;

use Illuminate\Support\Facades\Config;
use Livewire\Livewire;
use Nvade\Numerosis\Contracts\NamedFeature;
use Nvade\Numerosis\Livewire\Tenant as Tenants;

/**
 * The self-serve tenant registration wizard and its route.
 *
 * Remove it from `numerosis.features` and there is no self-serve signup.
 * Provisioning itself is unaffected — the wizard is only one caller of it,
 * so creating tenants from an admin screen or a job keeps working.
 */
class RegistrationWizardFeature implements NamedFeature
{
    public const NAME = 'registration_wizard';

    public static function featureName(): string
    {
        return self::NAME;
    }

    public function bootstrap(): void
    {
        $viewsPath = Config::string('numerosis.views.path');

        Livewire::addComponent(
            name: 'tenant-registration',
            viewPath: $viewsPath.'/livewire/tenant/registration/wizard/index.blade.php',
            class: Tenants\Registration\Registration::class,
        );
        Livewire::addComponent(
            name: 'plan',
            viewPath: $viewsPath.'/livewire/tenant/registration/wizard/steps/plan.blade.php',
            class: Tenants\Registration\Steps\Plan::class,
        );
        Livewire::addComponent(
            name: 'technical-setup',
            viewPath: $viewsPath.'/livewire/tenant/registration/wizard/steps/technical-setup.blade.php',
            class: Tenants\Registration\Steps\TechnicalSetup::class,
        );
        Livewire::addComponent(
            name: 'company-info',
            viewPath: $viewsPath.'/livewire/tenant/registration/wizard/steps/company-info.blade.php',
            class: Tenants\Registration\Steps\CompanyInfo::class,
        );
    }
}
