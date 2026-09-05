<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;

class WorkbenchServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * The `numerosis::` namespace only, not `NumerosisServiceProvider`
     * itself: the full provider also redirects model resolution, which
     * breaks the `App\Models\Central\*` subclass hinting many tests rely on.
     */
    public function boot(): void
    {
        $this->loadViewsFrom(dirname(__DIR__, 3).'/resources/views', 'numerosis');
    }
}
