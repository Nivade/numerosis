<?php

declare(strict_types=1);

/**
 * Reports what the module system does when `internachi/modular` cannot be
 * autoloaded, and prints the answer as JSON.
 *
 * Run as a subprocess by
 * {@see Nvade\Numerosis\Tests\Feature\Features\ModuleRegistryAbsenceTest},
 * because absence is only simulable in a process that has not already loaded
 * the package: `class_exists()` answers `true` for an already-declared class
 * no matter what the autoloader says. Pass `--without-modular` to unregister
 * Composer's loader and put a filtering wrapper in its place; without the
 * flag this is the positive control, proving the probe reports `true` when
 * the package really is there.
 *
 * No Laravel application is booted. `Features::forceForTesting()` is what
 * makes that possible — it answers the feature switch without a config
 * repository, so the only thing under test is the class-existence half.
 */

use Nvade\Numerosis\Features\Modules\ModuleSystemFeature;
use Nvade\Numerosis\Support\Features;

/** @var Composer\Autoload\ClassLoader $loader */
$loader = require dirname(__DIR__, 2).'/vendor/autoload.php';

/** @var list<string> $arguments */
$arguments = $_SERVER['argv'] ?? [];

if (in_array('--without-modular', $arguments, true)) {
    spl_autoload_unregister([$loader, 'loadClass']);

    spl_autoload_register(static function (string $class) use ($loader): void {
        if (str_starts_with($class, 'InterNACHI\\')) {
            return;
        }

        $loader->loadClass($class);
    });
}

Features::forceForTesting([ModuleSystemFeature::class]);

/**
 * Every class that names `InterNACHI\Modular\*`, plus the two that decide
 * whether the marketplace is reachable. All of them must still autoload: a
 * bare `use` import is lazy, so any of these turning into an `extends` /
 * `implements` / `use <Trait>` of a modular symbol is a fatal on a host that
 * declined the package, and this is what catches that.
 */
$classes = [
    ModuleSystemFeature::class,
    Nvade\Numerosis\Concerns\ResolvesInstalledModules::class,
    Nvade\Numerosis\Actions\Modules\PurchaseModule::class,
    Nvade\Numerosis\Actions\Modules\MigrateModules::class,
    Nvade\Numerosis\Actions\Modules\RollbackModules::class,
    Nvade\Numerosis\Actions\Modules\SynchronizeModules::class,
    Nvade\Numerosis\Console\Commands\MigrateTenantModule::class,
    Nvade\Numerosis\Console\Commands\RollbackTenantModule::class,
    Nvade\Numerosis\Console\Commands\SeedTenantModule::class,
    Nvade\Numerosis\NumerosisServiceProvider::class,
    Nvade\NumerosisFilament\TenantAdmin\Pages\Modules\Marketplace::class,
    Nvade\NumerosisFilament\TenantAdmin\Pages\Modules\ModuleDetail::class,
    Nvade\NumerosisFilament\TenantAdmin\Resources\Modules\ModuleResource::class,
];

$loaded = [];

foreach ($classes as $class) {
    try {
        $loaded[$class] = class_exists($class) || trait_exists($class);
    } catch (Throwable $e) {
        $loaded[$class] = $e::class.': '.$e->getMessage();
    }
}

echo json_encode([
    'modular_installed' => class_exists(InterNACHI\Modular\Support\Facades\Modules::class),
    'module_system_available' => ModuleSystemFeature::available(),
    'loaded' => $loaded,
], JSON_THROW_ON_ERROR);
