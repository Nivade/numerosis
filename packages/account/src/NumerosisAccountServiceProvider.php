<?php

declare(strict_types=1);

namespace Nvade\NumerosisAccount;

use Illuminate\Support\Facades\Config;
use Livewire\Livewire;
use Nvade\Numerosis\Support\Features;
use Nvade\Numerosis\Support\Numerosis;
use Nvade\NumerosisAccount\Features\AccountPagesFeature;
use Nvade\NumerosisAccount\Livewire\Settings\DeleteUserForm;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * The signed-in account UI: profile, password and appearance settings, the
 * workspace list, invoice downloads and the billing portal.
 *
 * These are *screens* plus the wiring that puts them on a URL. They used to
 * ship in `nvade/numerosis` alongside the tenancy and billing machinery, which
 * is what made "does this belong in core?" unanswerable — core was a framework
 * and a specific product at the same time. The mechanics they call
 * (`Actions\Auth\UpdateUserProfile`, `UpdateUserPassword`, `DeleteUserAccount`,
 * the billing models) stay in core, because core's own flows use them whether
 * or not this package is installed.
 */
class NumerosisAccountServiceProvider extends PackageServiceProvider
{
    /**
     * Views register under **`numerosis::`**, the namespace core already owns,
     * for the same reason `nvade/numerosis-ui` and `nvade/numerosis-auth-ui`
     * do: `addNamespace()` appends to a namespace's path list rather than
     * replacing it, so the moved views keep rendering as
     * `numerosis::livewire.settings.*` with no view-string edits. Nothing is
     * duplicated across the packages — these files were moved, not copied —
     * and must stay that way, since a same-named file resolves to whichever
     * package registered first, silently.
     */
    public function configurePackage(Package $package): void
    {
        $package
            ->name('numerosis-account')
            ->hasViews('numerosis');
    }

    /**
     * Everything happens in the register phase, deliberately:
     *
     * - `Features::register()` must land before core's `packageBooted()`
     *   feature-boot loop, and core's provider boots first (Composer discovers
     *   `nvade/numerosis` ahead of this package). Every provider's `register()`
     *   runs before any provider's `boot()`, so this is the only phase that is
     *   reliably early enough.
     * - route contributions must land before `Numerosis::routes()` runs, which
     *   happens during boot via `withRouting(using: ...)`.
     * - the Livewire view namespace must be set before `LivewireServiceProvider::boot()`
     *   reads `livewire.component_namespaces` and bakes it into the view
     *   finder's hints; a value set in any `boot()` is accepted by the config
     *   repository and changes nothing about how views actually resolve.
     */
    public function packageRegistered(): void
    {
        Features::register(AccountPagesFeature::class);

        $this->registerViewNamespace();
        $this->registerRoutes();

        // Addressed by dotted name from this package's own Blade views.
        // Livewire cannot discover package classes on its own.
        Livewire::addComponent(name: 'settings.delete-user-form', class: DeleteUserForm::class);
    }

    /**
     * `account-pages::` maps to this package's own single-file Livewire pages.
     *
     * Core's `pages::` namespace cannot be shared: `livewire.component_namespaces`
     * maps a prefix to exactly one directory, so appending is not possible the
     * way it is for a Blade *view* namespace. A separate prefix is the seam.
     */
    protected function registerViewNamespace(): void
    {
        if (Config::get('livewire.component_namespaces.account-pages') === null) {
            Config::set(
                'livewire.component_namespaces.account-pages',
                dirname(__DIR__).'/resources/views/pages',
            );
        }
    }

    /**
     * Central routes go through core's contribution seam rather than a route
     * file core loads, so they land inside the same
     * `Route::middleware('web')->domain($domain)` group, once per configured
     * central domain — the group a package cannot reproduce from outside.
     *
     * The feature check wraps the whole contribution rather than each route:
     * with the feature off this package registers nothing at all, and core's
     * redirects fall back to `home` on their own.
     */
    protected function registerRoutes(): void
    {
        $routes = dirname(__DIR__).'/routes';

        Numerosis::addCentralRoutes(function () use ($routes): void {
            if (! Features::enabled(AccountPagesFeature::NAME)) {
                return;
            }

            require $routes.'/account.php';
        }, source: 'nvade/numerosis-account');
    }
}
