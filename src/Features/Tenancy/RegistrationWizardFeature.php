<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Features\Tenancy;

use Illuminate\Support\Facades\Config;
use Livewire\Livewire;
use Nvade\Numerosis\Contracts\NamedFeature;
use Nvade\Numerosis\Livewire\Tenant as Tenants;

/**
 * The self-serve tenant registration wizard: the tenants.create route and
 * its four Livewire step components. Remove this class from
 * config('numerosis.features') and a deployment has no self-serve signup at
 * all — nothing here provisions tenants directly.
 *
 * Tenant provisioning itself is unaffected by this toggle: everything funnels
 * through Nvade\Numerosis\Contracts\Tenancy\ProvisionsTenant::queue()
 * (.claude/rules/tenant-provisioning.md), which the wizard only calls. A
 * consumer creating tenants from an admin screen or a job keeps working with
 * this feature off.
 *
 * Livewire registration uses config('numerosis.views.path') rather than
 * hardcoded resource_path(), so the path is swappable during package
 * extraction (Phase 1.5). Post-split, the package's NumerosisServiceProvider
 * updates this config value.
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
