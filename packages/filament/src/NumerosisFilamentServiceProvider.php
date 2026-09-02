<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament;

use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Support\Features;
use Nvade\NumerosisFilament\Features\ActivityLogFeature;
use Nvade\NumerosisFilament\Features\AdminPanelFeature;
use Nvade\NumerosisFilament\Features\TenantPanelFeature;
use Nvade\NumerosisFilament\Providers\NumerosisAdminPanelProvider;
use Nvade\NumerosisFilament\Providers\NumerosisTenantPanelProvider;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class NumerosisFilamentServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        // Deliberately the *same* view namespace core uses. FileViewFinder::
        // addNamespace() appends rather than replaces, so both packages serve
        // `numerosis::` and every `numerosis::filament.*` reference in this
        // package's own pages keeps resolving with no edit.
        $package
            ->name('numerosis-filament')
            ->hasViews('numerosis');
    }

    /**
     * Register phase, not boot: core's provider is discovered first and reads
     * both of these while booting — `Features::all()` on the feature side, and
     * the panel providers have to be registered before Filament's own boot
     * work runs.
     */
    public function packageRegistered(): void
    {
        Features::register(AdminPanelFeature::class);
        Features::register(TenantPanelFeature::class);
        Features::register(ActivityLogFeature::class);

        $this->registerPanelProviders();
        $this->registerDefaultModulePlugins();
    }

    /**
     * `numerosis.modules.plugins` (slug => Filament plugin class) is read
     * only by `NumerosisTenantPlugin` in this package — core's own module
     * system never references it, only `numerosis.modules.catalogue` — so
     * this package owns the default rather than core declaring an empty
     * array for a key it never reads. Deferred to `booting()`, same
     * reasoning as `registerPanelProviders()`: core's own `mergeConfigFrom()`
     * has to have populated `numerosis.modules` first, or `Arr::set()`
     * auto-vivifies a partial `modules` array that then wins over core's
     * `catalogue` default when core's merge runs afterward. A host that
     * configured its own map wins: its published config file is loaded
     * before any provider runs.
     */
    protected function registerDefaultModulePlugins(): void
    {
        $this->app->booting(function (): void {
            if (Config::get('numerosis.modules.plugins') === null) {
                Config::set('numerosis.modules.plugins', []);
            }
        });
    }

    /**
     * Registers this package's two panel providers.
     *
     * `numerosis.panels.{admin,tenant}.provider` is core's escape hatch for a
     * host replacing either panel wholesale; core registers whatever it names,
     * so this package must stand down for that panel rather than register a
     * second provider for the same panel id.
     *
     * Whether a panel registers at all is still each provider's own
     * `shouldRegisterPanel()` decision — this only chooses the class.
     */
    protected function registerPanelProviders(): void
    {
        $this->app->booting(function (): void {
            foreach (self::panelProvidersToRegister() as $provider) {
                $this->app->register($provider);
            }
        });
    }

    /**
     * Which of this package's panel providers should register, given what a
     * host has claimed in core's config.
     *
     * Split out from the `booting()` callback so the decision is directly
     * assertable — registering a second provider for a panel id a host has
     * already claimed does not fail, it silently produces two panels fighting
     * over one id, which is exactly the shape of failure this whole split
     * keeps producing.
     *
     * @return list<class-string>
     */
    public static function panelProvidersToRegister(): array
    {
        // Core's config has to be merged before either panel can register:
        // both plugins read `numerosis.features` and the guard names under
        // `numerosis.auth.guards.*` from their own `register()`. Core's
        // provider is normally discovered ahead of this one and merges it —
        // but "normally" is doing real work there, and the failure when it
        // does not is a hard `Configuration value for key [...] must be a
        // string, NULL given` from deep inside Filament's own boot, nowhere
        // near the cause. Registering nothing is the honest outcome: without
        // core there is no tenancy, no guards and no features to hang a panel
        // on. (Reproducible: Larastan boots an application that discovers
        // every vendor package but not the root one.)
        //
        // The sentinel is `numerosis.features`, not `numerosis.panels`:
        // nvade/numerosis-auth-ui fills `numerosis.panels.tenant.login` with
        // `Config::set()`, and `Arr::set()` auto-vivifies every missing
        // segment on the way — so `numerosis.panels` can be a non-null array
        // that core never merged. `features` is core's alone.
        if (Config::get('numerosis.features') === null) {
            return [];
        }

        $providers = [];

        if (Config::get('numerosis.panels.admin.provider') === null) {
            $providers[] = NumerosisAdminPanelProvider::class;
        }

        if (Config::get('numerosis.panels.tenant.provider') === null) {
            $providers[] = NumerosisTenantPanelProvider::class;
        }

        return $providers;
    }
}
