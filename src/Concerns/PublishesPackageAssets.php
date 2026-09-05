<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Concerns;

/**
 * Wrapper around `Illuminate\Support\ServiceProvider::publishes()` for a
 * provider declaring several publish groups behind one `runningInConsole()`
 * check. It saves nothing but that repeated guard, and exists so a second
 * provider with its own publish groups does not write it by hand again.
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
