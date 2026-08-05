<?php

declare(strict_types=1);

namespace Nvade\Numerosis;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Commands\InstallNumerosisCommand;
use Nvade\Numerosis\Commands\NumerosisCommand;
use Nvade\Numerosis\Concerns\PublishesPackageAssets;
use Nvade\Numerosis\Console\Commands\DeleteTenants;
use Nvade\Numerosis\Console\Commands\MigrateTenantModule;
use Nvade\Numerosis\Console\Commands\PruneOrphanedStripeCustomers;
use Nvade\Numerosis\Console\Commands\PruneOrphanedTenantDatabases;
use Nvade\Numerosis\Console\Commands\PruneStalledTenantProvisions;
use Nvade\Numerosis\Console\Commands\RollbackTenantModule;
use Nvade\Numerosis\Console\Commands\SeedTenantModule;
use Nvade\Numerosis\Providers\BillingServiceProvider;
use Nvade\Numerosis\Providers\TenancyServiceProvider;
use Nvade\Numerosis\Support\Features;
use Nvade\Numerosis\Support\Numerosis;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class NumerosisServiceProvider extends PackageServiceProvider
{
    use PublishesPackageAssets;

    public function configurePackage(Package $package): void
    {
        $package
            ->name('numerosis')
            ->hasConfigFile(['numerosis', 'numerosis-tenancy', 'numerosis-billing'])
            ->hasViews()
            ->hasTranslations()
            ->discoversMigrations(true, '/database/migrations/central')
            ->runsMigrations()
            ->hasCommand(NumerosisCommand::class)
            ->hasCommand(InstallNumerosisCommand::class)
            ->hasCommand(DeleteTenants::class)
            ->hasCommand(MigrateTenantModule::class)
            ->hasCommand(RollbackTenantModule::class)
            ->hasCommand(SeedTenantModule::class)
            ->hasCommand(PruneOrphanedStripeCustomers::class)
            ->hasCommand(PruneOrphanedTenantDatabases::class)
            ->hasCommand(PruneStalledTenantProvisions::class);
    }

    public function packageRegistered(): void
    {
        // TenancyServiceProvider and BillingServiceProvider merge their own
        // config/numerosis-{tenancy,billing}.php via mergeConfigFrom() in
        // their own register() — registering them here, rather than
        // hardcoding them into composer.json's extra.laravel.providers list
        // alongside this class, keeps them swappable the same way a
        // consumer can already replace any other package binding.
        $this->app->register(TenancyServiceProvider::class);
        $this->app->register(BillingServiceProvider::class);
    }

    public function packageBooted(): void
    {
        // RegistrationWizardFeature reads this to register its Livewire
        // components' view paths — see its own docblock. Set here rather
        // than left as the extraction-era base_path() default in
        // config/numerosis.php, now that views actually ship from the
        // package.
        Config::set('numerosis.views.path', __DIR__.'/../resources/views');

        // Laravel's default factory-name guesser rebuilds the factory class
        // under the *model's own* root namespace — correct for a
        // single-repo app, wrong once the model is a thin-app stub
        // (App\Models\Central\Tenant) whose factory actually lives in this
        // package. See Numerosis::factoryNameFor()'s docblock.
        Factory::guessFactoryNamesUsing(Numerosis::factoryNameFor(...));

        // The reverse direction: `Tenant::factory()` on a host-published stub
        // must build a `Tenant\TenantFactory` that yields the *stub*, not the
        // abstract package model — but a factory for a model with no stub
        // (`Membership`, `Role`, …) must still yield the package model
        // directly. See Numerosis::modelNameFor()'s docblock.
        Factory::guessModelNamesUsing(fn (Factory $factory): string => Numerosis::modelNameFor($factory::class));

        foreach (Features::all() as $feature) {
            $this->app->make($feature)->bootstrap();
        }

        // Tenant migrations are never auto-run centrally — stancl runs them
        // per-tenant via config('tenancy.migration_parameters'), which the
        // host must point at this absolute vendor path (see
        // docs/host-requirements.md). Only published here, so a consumer
        // can copy and customize them without vendor-patching.
        $this->publishGroup([
            __DIR__.'/../database/migrations/tenant' => database_path('migrations/tenant'),
        ], 'numerosis-tenant-migrations');

        $this->publishGroup([
            __DIR__.'/../resources/css' => resource_path('vendor/numerosis/css'),
            __DIR__.'/../resources/js' => resource_path('vendor/numerosis/js'),
        ], 'numerosis-assets');

        // The 9 concrete model stubs (see .claude/rules — 4.4's "abstract
        // base in the package, concrete in thin-app"). 'numerosis-models'
        // and 'numerosis-stubs' both point at the same file set: there is
        // nothing else stub-shaped in this package to give the second tag
        // a distinct meaning, and a consumer publishing either tag needs
        // to end up with the same 9 files regardless of which name they
        // used.
        $modelStubs = [
            __DIR__.'/../stubs/Models/Central/Tenant.stub' => app_path('Models/Central/Tenant.php'),
            __DIR__.'/../stubs/Models/Central/Domain.stub' => app_path('Models/Central/Domain.php'),
            __DIR__.'/../stubs/Models/Central/CentralUser.stub' => app_path('Models/Central/CentralUser.php'),
            __DIR__.'/../stubs/Models/Central/Subscription.stub' => app_path('Models/Central/Subscription.php'),
            __DIR__.'/../stubs/Models/Central/PaymentPlan.stub' => app_path('Models/Central/PaymentPlan.php'),
            __DIR__.'/../stubs/Models/Central/PendingTenantProvision.stub' => app_path('Models/Central/PendingTenantProvision.php'),
            __DIR__.'/../stubs/Models/Tenant/User.stub' => app_path('Models/Tenant/User.php'),
            __DIR__.'/../stubs/Models/Tenant/Invitation.stub' => app_path('Models/Tenant/Invitation.php'),
            __DIR__.'/../stubs/Models/Tenant/Module.stub' => app_path('Models/Tenant/Module.php'),
        ];

        $this->publishGroup($modelStubs, 'numerosis-models');
        $this->publishGroup($modelStubs, 'numerosis-stubs');
    }
}
