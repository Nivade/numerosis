<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Concerns;

/**
 * Small wrapper around `Illuminate\Support\ServiceProvider::publishes()` for
 * service providers that declare several publish groups guarded by the same
 * `runningInConsole()` check — `NumerosisServiceProvider::packageBooted()`
 * is the first consumer. Saves nothing over calling `publishes()` directly
 * except the repeated console-check; exists so a second provider with its
 * own publish groups (a future satellite provider, say) doesn't have
 * to repeat that guard by hand.
 */
trait PublishesPackageAssets
{
    /**
     * @param  array<string, string>  $paths
     */
    protected function publishGroup(array $paths, string $tag): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes($paths, $tag);
    }
}
