<?php

declare(strict_types=1);

namespace Nvade\NumerosisUi;

use Illuminate\Support\Facades\Blade;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * The shared Blade layer every other Numerosis package renders through.
 *
 * This is the one package in the set that is a genuine leaf: nothing in it
 * references tenancy, billing, Filament, or `nvade/numerosis` itself. Every
 * other package depends on it — including core — so it is not optional.
 *
 * That leaf property is what decided its contents, and it is narrower than
 * the plan's original list. `resources/views/layouts` and
 * `resources/views/partials` were moved here and moved straight back: they
 * name `Nvade\Numerosis\Support\{Numerosis,Features,Routes\RouteNames}`,
 * `Models\Central\CentralUser` and `tenancy()`, so shipping them here would
 * have inverted the dependency this package exists to avoid. What is here is
 * `components/ui`, `components/icons`, `flux` and `placeholder-pattern` —
 * verified to reference no PHP class, route, or tenancy helper at all.
 */
class NumerosisUiServiceProvider extends PackageServiceProvider
{
    /**
     * Views register under **`numerosis::`**, the same namespace
     * `nvade/numerosis` uses, rather than a `numerosis-ui::` of its own.
     *
     * That is deliberate and is what made this split cost zero view edits:
     * `FileViewFinder::addNamespace()` *appends* to a namespace's path list
     * rather than replacing it, so two packages can serve one namespace and
     * all ~139 `<x-numerosis::ui.*>` / `numerosis::partials.*` references
     * across the other packages keep resolving unchanged. Blade view
     * references are strings no static analysis checks, so leaving them
     * alone is worth more here than a tidier namespace boundary.
     *
     * Consequence to know: paths are searched in registration order, so if
     * a file of the same name existed in both packages, the first-registered
     * one would win silently. Nothing is duplicated today — these files were
     * moved, not copied — and they should stay that way.
     */
    public function configurePackage(Package $package): void
    {
        $package
            ->name('numerosis-ui')
            ->hasViews('numerosis');
    }

    public function packageBooted(): void
    {
        // Lucide icons the Numerosis views use that Flux does not ship.
        // Deferred to booted() so it lands after Flux's own paths, leaving
        // Flux's (and a host's) versions taking precedence over these.
        $this->app->booted(function (): void {
            Blade::anonymousComponentPath(__DIR__.'/../resources/views/flux', 'flux');
        });
    }
}
