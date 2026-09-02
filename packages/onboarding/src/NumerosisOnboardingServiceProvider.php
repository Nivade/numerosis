<?php

declare(strict_types=1);

namespace Nvade\NumerosisOnboarding;

use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Support\Features;
use Nvade\Numerosis\Support\Numerosis;
use Nvade\NumerosisOnboarding\Features\RegistrationWizardFeature;
use Nvade\NumerosisOnboarding\Livewire\Steps\CompanyInfo;
use Nvade\NumerosisOnboarding\Livewire\Steps\Payment;
use Nvade\NumerosisOnboarding\Livewire\Steps\Plan;
use Nvade\NumerosisOnboarding\Livewire\Steps\TechnicalSetup;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * The self-serve tenant registration wizard: `/get-started`, its four steps,
 * and the wizard state object that carries values between them.
 *
 * Provisioning itself stays in `nvade/numerosis` — the wizard is one caller
 * of `ProvisionsTenant::queue()`, not the mechanism. So is everything a step
 * *uses*: `Actions\Tenancy\{ReserveTenantDomain,CreateTenantDomain}`,
 * `Rules\{DomainIsAvailable,CustomDomainIsAvailable}`,
 * `Contracts\Tenancy\ProvidesTenantIdentity`, `Data\Tenancy\TenantRegistrationData`
 * and the whole checkout surface. Uninstall this package and tenants can
 * still be created from an admin screen, a job, or a Stripe webhook.
 */
class NumerosisOnboardingServiceProvider extends PackageServiceProvider
{
    /**
     * Views register under **`numerosis::`**, the namespace core already owns,
     * for the same reason `nvade/numerosis-ui` and `-auth-ui` do:
     * `addNamespace()` appends to a namespace's path list rather than
     * replacing it, so `numerosis::livewire.tenant.registration.*` and
     * `<x-numerosis::registration.*>` keep resolving with no view-string
     * edits. The files were moved, not copied — a same-named file in two
     * packages resolves to whichever registered first, silently.
     */
    public function configurePackage(Package $package): void
    {
        $package
            ->name('numerosis-onboarding')
            ->hasViews('numerosis');
    }

    /**
     * Everything happens in the register phase, deliberately:
     *
     * - `Features::register()` must land before core's `packageBooted()`
     *   feature-boot loop, and core's provider boots first. Every provider's
     *   `register()` runs before any provider's `boot()`, so this is the only
     *   phase reliably early enough.
     * - the route contribution must land before `Numerosis::routes()` runs,
     *   which happens during boot via `withRouting(using: ...)`.
     *
     * Nothing here *reads* core's config to decide whether to wire itself up.
     * That asymmetry is the rule, not an accident: a satellite must be able to
     * register into a world where core's config was never merged (Larastan
     * boots exactly that application) and simply do nothing harmful. Writes
     * auto-vivify and are deep-filled by `HostConfig`; reads belong in a
     * `booting()` callback. See `.claude/rules/package-split.md`.
     */
    public function packageRegistered(): void
    {
        Features::register(RegistrationWizardFeature::class);

        $this->registerRoutes();

        // Deferred to `booting()`, which is NOT the phase the other
        // satellites write config in — see registerDefaultSteps() for why
        // this one has to be different.
        $this->app->booting($this->registerDefaultSteps(...));
    }

    /**
     * The wizard's own default step list.
     *
     * It lives here rather than in core's `config/numerosis.php` because the
     * four classes are this package's, and a host that declines this package
     * would otherwise find core's config naming classes that do not exist.
     *
     * **This write must happen in the `booting()` phase, unlike
     * `numerosis-auth-ui`'s `numerosis.panels.tenant.login`.** `Arr::set()`
     * auto-vivifies every missing segment, and provider *register* order
     * between two discovered packages is not ours to choose — so a
     * register-phase write can land before core's `mergeConfigFrom()` and
     * create a `numerosis.tenancy` array of this package's own invention.
     * `mergeConfigFrom()`'s `array_merge()` is one level deep, so it then
     * keeps that partial array wholesale and core's real `tenancy` block is
     * discarded: `implementations`, `provisioning`, `identification`, all of
     * it. Measured, not theorised — it produced
     * `Target [Nvade\Numerosis\Contracts\Tenancy\ProvisionsTenant] is not
     * instantiable` from a Filament billing page, ~40 failures away from the
     * cause. `numerosis.panels` survives the same treatment only because
     * `HostConfig` deep-fills it; `numerosis.tenancy` is not deep-filled.
     * See `.claude/rules/package-host-bootstrap.md`.
     *
     * `booting()` runs after every provider's `register()`, so core's merge
     * (and `HostConfig::apply()`, itself a `booting()` callback registered
     * earlier) has already happened, and before core's `packageBooted()`
     * feature-boot loop reads this key. A host that configured its own steps
     * wins: its published config file is loaded before any provider runs.
     */
    protected function registerDefaultSteps(): void
    {
        if (Config::get('numerosis.tenancy.registration.steps') === null) {
            Config::set('numerosis.tenancy.registration.steps', [
                CompanyInfo::class,
                TechnicalSetup::class,
                Plan::class,
                Payment::class,
            ]);
        }
    }

    /**
     * Goes through core's contribution seam rather than registering a route
     * directly, so `/get-started` lands inside the
     * `Route::middleware('web')->domain($domain)` group core builds once per
     * central domain. A bare `Route::get()` here would answer on every tenant
     * subdomain as well, and nothing would fail.
     */
    protected function registerRoutes(): void
    {
        $routes = dirname(__DIR__).'/routes';

        Numerosis::addCentralRoutes(function () use ($routes): void {
            require $routes.'/onboarding.php';
        }, source: 'nvade/numerosis-onboarding');
    }
}
