<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Concerns;

use Illuminate\Console\Command;
use InterNACHI\Modular\Support\Facades\Modules;
use InterNACHI\Modular\Support\ModuleConfig;
use Nvade\Numerosis\Features\Modules\ModuleSystemFeature;

/**
 * Looks a module up in the registry, for the three `tenants:*-module`
 * commands.
 *
 * `internachi/modular` is `suggest`, so the registry may not exist at all.
 * The three commands are only registered when it does — this is the guard for
 * a command constructed directly, and the one place the three of them agree
 * on what "module not found" prints.
 *
 * @phpstan-require-extends Command
 */
trait ResolvesInstalledModules
{
    /**
     * The named module, or null after reporting why it could not be resolved.
     * A null return means the caller should return `Command::FAILURE`.
     */
    protected function installedModule(string $name): ?ModuleConfig
    {
        if (! ModuleSystemFeature::available()) {
            $this->error('The module system is unavailable: install internachi/modular and enable the [modules] feature.');

            return null;
        }

        $module = Modules::module($name);

        if (! $module instanceof ModuleConfig) {
            $this->error("Module [{$name}] not found.");

            return null;
        }

        return $module;
    }
}
