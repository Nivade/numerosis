<?php

declare(strict_types=1);

namespace Nvade\NumerosisUi;

use Illuminate\Support\Facades\Blade;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * The shared Blade layer every other Numerosis package renders through.
 *
 * A genuine leaf: nothing here may reference tenancy, billing or
 * `nvade/numerosis` itself, since core depends on this rather than the
 * reverse.
 */
class NumerosisUiServiceProvider extends PackageServiceProvider
{
    /**
     * Views register under `numerosis::`, the namespace core uses, because
     * `FileViewFinder::addNamespace()` appends to a namespace's path list
     * rather than replacing it. Paths are searched in registration order, so
     * a file of the same name in both packages would resolve to the
     * first-registered one silently. Nothing is duplicated today.
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
